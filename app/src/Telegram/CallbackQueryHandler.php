<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Entity\TelegramGroup;
use App\Entity\User;
use App\Entity\UserGroupSubscription;
use App\Repository\TelegramGroupRepository;
use App\Repository\UserGroupRepository;
use App\Repository\UserGroupSubscriptionRepository;
use App\Service\AskAiTelegramService;
use App\Service\TelegramBotClient;
use App\Service\UserUpsertService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class CallbackQueryHandler
{
    private const string CONTEXT_EDIT_FAIL_ALERT = 'Не удалось обновить сообщение. В группе проверьте права бота; в форуме — что сообщение в том же топике.';

    public function __construct(
        private readonly TelegramGroupRepository $groupRepository,
        private readonly TelegramBotClient $telegramClient,
        private readonly GroupAdminCallbackHandler $groupAdminCallbackHandler,
        private readonly PrivateChatHandler $privateChatHandler,
        private readonly UserUpsertService $userUpsertService,
        private readonly UserGroupRepository $userGroupRepository,
        private readonly UserGroupSubscriptionRepository $subscriptionRepository,
        private readonly EntityManagerInterface $em,
        private readonly ContextSummaryFoldCache $contextSummaryFoldCache,
        private readonly AiReplyFoldCache $aiReplyFoldCache,
        private readonly LoggerInterface $logger,
        private readonly AskAiTelegramService $askAiTelegramService,
    ) {
    }

    /**
     * @param array<string, mixed> $callbackQuery
     */
    public function handle(array $callbackQuery): void
    {
        $data = (string) ($callbackQuery['data'] ?? '');
        $queryId = (string) ($callbackQuery['id'] ?? '');

        if ('ai_toggle' === $data) {
            $this->handleAiReplyToggle($callbackQuery);

            return;
        }

        if ('ctx_toggle' === $data) {
            $this->handleContextToggle($callbackQuery);

            return;
        }

        if (str_starts_with($data, 'sub_')) {
            $this->handlePrivateSubscriptionCallback($callbackQuery);

            return;
        }

        if (str_starts_with($data, 'ga_')) {
            $this->groupAdminCallbackHandler->handle($callbackQuery);

            return;
        }

        if ('priv_my_chats' === $data) {
            $this->handlePrivMyChats($callbackQuery);

            return;
        }

        if ('priv_help' === $data) {
            $this->handlePrivHelp($callbackQuery);

            return;
        }

        if ('priv_ask_ai' === $data || 'ask_ai' === $data) {
            $this->handlePrivAskAi($callbackQuery);

            return;
        }

        if ('priv_noop' === $data) {
            $this->telegramClient->answerCallbackQuery($queryId);

            return;
        }

        $this->telegramClient->answerCallbackQuery($queryId);
    }

    /**
     * Свернуть / развернуть ответ ИИ (полный текст в Redis, TTL 3 ч).
     *
     * @param array<string, mixed> $callbackQuery
     */
    private function handleAiReplyToggle(array $callbackQuery): void
    {
        $queryId = (string) ($callbackQuery['id'] ?? '');
        $message = $callbackQuery['message'] ?? [];
        if (!\is_array($message)) {
            $message = [];
        }

        $chatId = (int) ($message['chat']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);
        $textRaw = (string) ($message['text'] ?? '');
        $text = str_replace(["\r\n", "\r"], "\n", $textRaw);
        $messageThreadId = TelegramBotClient::messageThreadIdFromMessage($message);

        if (0 === $chatId || $messageId <= 0 || '' === $text) {
            $this->telegramClient->answerCallbackQuery($queryId);

            return;
        }

        $marker = AiReplyFoldCache::COLLAPSED_MARKER;
        $hasCollapsedMarker = str_contains($text, $marker);

        if ($hasCollapsedMarker) {
            $fullText = $this->aiReplyFoldCache->fetch($chatId, $messageId);
            if (null === $fullText) {
                $this->telegramClient->answerCallbackQuery(
                    $queryId,
                    'Развернуть нельзя: полный текст в кэше не найден (истёк срок 3 ч, Redis недоступен или старое сообщение).',
                    true,
                );

                return;
            }

            $ok = $this->editContextMessageWithKeyboard(
                $chatId,
                $messageId,
                $fullText,
                [
                    [
                        [
                            'text' => '🫥 Свернуть',
                            'callback_data' => 'ai_toggle',
                        ],
                    ],
                ],
                $messageThreadId,
                $message,
            );
            if ($ok) {
                $this->aiReplyFoldCache->delete($chatId, $messageId);
            } else {
                $this->telegramClient->answerCallbackQuery($queryId, self::CONTEXT_EDIT_FAIL_ALERT, true);

                return;
            }
        } else {
            $this->aiReplyFoldCache->save($chatId, $messageId, $text);
            $ok = $this->editContextMessageWithKeyboard(
                $chatId,
                $messageId,
                $marker,
                [
                    [
                        [
                            'text' => '📜 Показать',
                            'callback_data' => 'ai_toggle',
                        ],
                    ],
                ],
                $messageThreadId,
                $message,
            );
            if (!$ok) {
                $this->logger->warning('ai_toggle collapse: edit failed after save (redis rolled back)');
                $this->aiReplyFoldCache->delete($chatId, $messageId);
                $this->telegramClient->answerCallbackQuery($queryId, self::CONTEXT_EDIT_FAIL_ALERT, true);

                return;
            }
        }

        $this->telegramClient->answerCallbackQuery($queryId);
    }

    /**
     * Свёрнутое сообщение в Telegram — только шапка и маркер; полный текст хранится в Redis.
     * Поддержка старых сообщений с tg-spoiler (развёртывание без Redis).
     *
     * @param array<string, mixed> $callbackQuery
     */
    private function handleContextToggle(array $callbackQuery): void
    {
        $queryId = (string) ($callbackQuery['id'] ?? '');
        $message = $callbackQuery['message'] ?? [];
        if (!\is_array($message)) {
            $message = [];
        }

        $chatId = (int) ($message['chat']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);
        $textRaw = (string) ($message['text'] ?? '');
        $text = str_replace(["\r\n", "\r"], "\n", $textRaw);
        $messageThreadId = TelegramBotClient::messageThreadIdFromMessage($message);
        $chatForLog = $message['chat'] ?? [];
        $chatTypeLog = \is_array($chatForLog) ? (string) ($chatForLog['type'] ?? '') : '';

        // chat_id в группах отрицательный (-100…); нельзя использовать <= 0.
        if (0 === $chatId || $messageId <= 0 || '' === $text) {
            $this->logger->info('ctx_toggle skip: invalid chat/message/text', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text_empty' => '' === $text,
            ]);

            $this->telegramClient->answerCallbackQuery($queryId);

            return;
        }

        $hasSpoiler = 1 === preg_match('/<tg-spoiler[^>]*>[\s\S]*<\/tg-spoiler>/', $text);
        $hasCollapsedMarker = str_contains($text, '📄 Саммари свернуто');

        $this->logger->info('ctx_toggle', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'chat_type' => $chatTypeLog,
            'message_thread_id' => $messageThreadId,
            'is_forum' => \is_array($chatForLog) ? ($chatForLog['is_forum'] ?? null) : null,
            'has_is_forum_key' => \is_array($chatForLog) && \array_key_exists('is_forum', $chatForLog),
            'branch' => $hasSpoiler ? 'spoiler' : ($hasCollapsedMarker ? 'expand_from_cache' : 'collapse'),
            'text_len' => \strlen($text),
            'has_double_newline' => str_contains($text, "\n\n"),
        ]);

        if ($hasSpoiler) {
            if (!preg_match('/\A([\s\S]*?)\n\n<tg-spoiler[^>]*>([\s\S]*)<\/tg-spoiler>\s*\z/u', $text, $m)) {
                $this->logger->info('ctx_toggle spoiler branch: regex mismatch');

                $this->telegramClient->answerCallbackQuery($queryId);

                return;
            }

            $fullText = $m[1]."\n\n".$m[2];

            $ok = $this->editContextMessageWithKeyboard(
                $chatId,
                $messageId,
                $fullText,
                [
                    [
                        [
                            'text' => '🫥 Свернуть',
                            'callback_data' => 'ctx_toggle',
                        ],
                    ],
                ],
                $messageThreadId,
                $message,
            );
            if (!$ok) {
                $this->telegramClient->answerCallbackQuery($queryId, self::CONTEXT_EDIT_FAIL_ALERT, true);

                return;
            }
        } elseif ($hasCollapsedMarker) {
            $fullText = $this->contextSummaryFoldCache->fetch($chatId, $messageId);
            if (null === $fullText) {
                $this->telegramClient->answerCallbackQuery(
                    $queryId,
                    'Развернуть нельзя: полный текст в кэше не найден (истёк срок, Redis недоступен или старое сообщение).',
                    true,
                );

                return;
            }

            $ok = $this->editContextMessageWithKeyboard(
                $chatId,
                $messageId,
                $fullText,
                [
                    [
                        [
                            'text' => '🫥 Свернуть',
                            'callback_data' => 'ctx_toggle',
                        ],
                    ],
                ],
                $messageThreadId,
                $message,
            );
            if ($ok) {
                $this->contextSummaryFoldCache->delete($chatId, $messageId);
            } else {
                $this->telegramClient->answerCallbackQuery($queryId, self::CONTEXT_EDIT_FAIL_ALERT, true);

                return;
            }
        } else {
            $parts = preg_split('/\n\n/', $text, 2);
            if (\count($parts) < 2) {
                $this->logger->info('ctx_toggle collapse: no \\n\\n split', [
                    'text_len' => \strlen($text),
                    'newline_count' => substr_count($text, "\n"),
                ]);

                $this->telegramClient->answerCallbackQuery(
                    $queryId,
                    'Не получается свернуть: в тексте нет раздела между шапкой и саммари (ожидается пустая строка между ними).',
                    true,
                );

                return;
            }

            $header = $parts[0];

            $this->contextSummaryFoldCache->save($chatId, $messageId, $text);
            $collapsedText = $header."\n\n📄 Саммари свернуто";

            $ok = $this->editContextMessageWithKeyboard(
                $chatId,
                $messageId,
                $collapsedText,
                [
                    [
                        [
                            'text' => '📜 Показать',
                            'callback_data' => 'ctx_toggle',
                        ],
                    ],
                ],
                $messageThreadId,
                $message,
            );
            if (!$ok) {
                $this->logger->warning('ctx_toggle collapse: edit failed after save (redis rolled back)');
                $this->contextSummaryFoldCache->delete($chatId, $messageId);
                $this->telegramClient->answerCallbackQuery($queryId, self::CONTEXT_EDIT_FAIL_ALERT, true);

                return;
            }
        }

        $this->logger->info('ctx_toggle done', ['chat_id' => $chatId, 'message_id' => $messageId]);

        $this->telegramClient->answerCallbackQuery($queryId);
    }

    /**
     * editMessageText + повтор с message_thread_id=1, если в callback не было топика (форум: «Общий» часто = 1).
     * Повтор и для group, и для supergroup — в апдейте тип может быть не только supergroup.
     *
     * @param array<int, array<int, array{text: string, callback_data: string}>> $keyboard
     * @param array<string, mixed>                                                $message
     */
    private function editContextMessageWithKeyboard(
        int $chatId,
        int $messageId,
        string $text,
        array $keyboard,
        ?int $messageThreadId,
        array $message,
    ): bool {
        $ok = $this->telegramClient->editMessageTextWithKeyboard(
            $chatId,
            $messageId,
            $text,
            $keyboard,
            $messageThreadId,
        );
        if ($ok) {
            return true;
        }

        $chat = $message['chat'] ?? [];
        $chatType = \is_array($chat) ? (string) ($chat['type'] ?? '') : '';

        if (null !== $messageThreadId) {
            $this->logger->warning('ctx_toggle edit: no retry (thread already in callback)', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'chat_type' => $chatType,
                'message_thread_id' => $messageThreadId,
            ]);

            return false;
        }

        // Форумные топики: в callback часто нет message_thread_id; «Общий» обычно id=1.
        // Тип чата в апдейте может быть group или supergroup — раньше retry был только для supergroup.
        if (!\in_array($chatType, ['group', 'supergroup'], true)) {
            $this->logger->warning('ctx_toggle edit: no retry (not a group chat)', [
                'chat_id' => $chatId,
                'chat_type' => $chatType,
            ]);

            return false;
        }

        $okRetry = $this->telegramClient->editMessageTextWithKeyboard(
            $chatId,
            $messageId,
            $text,
            $keyboard,
            1,
        );
        $this->logger->warning('ctx_toggle edit: retry with message_thread_id=1', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'chat_type' => $chatType,
            'ok' => $okRetry,
        ]);

        return $okRetry;
    }

    /**
     * Подписка в личке: sub_{groupId}.
     *
     * @param array<string, mixed> $callbackQuery
     */
    private function handlePrivateSubscriptionCallback(array $callbackQuery): void
    {
        $queryId = (string) ($callbackQuery['id'] ?? '');
        $data = (string) ($callbackQuery['data'] ?? '');
        $message = $callbackQuery['message'] ?? [];
        $chat = \is_array($message) ? ($message['chat'] ?? []) : [];
        $chatType = \is_array($chat) ? (string) ($chat['type'] ?? '') : '';

        if ('private' !== $chatType) {
            $this->telegramClient->answerCallbackQuery($queryId, 'Подписка доступна только в личке с ботом.', true);

            return;
        }

        if (!preg_match('/^sub_(\d+)$/', $data, $m)) {
            $this->telegramClient->answerCallbackQuery($queryId);

            return;
        }

        $groupId = (int) $m[1];
        $group = $this->groupRepository->find($groupId);

        if (null === $group || !$group->isActive()) {
            $this->telegramClient->answerCallbackQuery($queryId, 'Группа недоступна.', true);

            return;
        }

        $from = $callbackQuery['from'] ?? [];
        $user = $this->userUpsertService->findOrCreate($from);

        if (null === $this->userGroupRepository->findOneBy(['user' => $user, 'group' => $group])) {
            $this->telegramClient->answerCallbackQuery(
                $queryId,
                'Этот чат недоступен. Напиши в группе хотя бы одно сообщение при включённом боте.',
                true,
            );

            return;
        }

        $this->toggleSubscription($user, $group);

        $this->telegramClient->answerCallbackQuery($queryId, 'Подписка обновлена');

        $chatId = (int) ($chat['id'] ?? 0);
        $messageId = (int) (\is_array($message) ? ($message['message_id'] ?? 0) : 0);

        if ($chatId > 0 && $messageId > 0) {
            $payload = $this->privateChatHandler->buildMyChatsListPayload($user);
            if (null !== $payload) {
                $threadId = \is_array($message) ? TelegramBotClient::messageThreadIdFromMessage($message) : null;
                $this->telegramClient->editMessageTextWithKeyboard(
                    $chatId,
                    $messageId,
                    $payload['text'],
                    $payload['keyboard'],
                    $threadId,
                );
            } else {
                $this->telegramClient->sendMessage(
                    $chatId,
                    'Пока здесь пусто: в списке только чаты, где ты уже писал при включённом боте.',
                );
            }
        }
    }

    private function toggleSubscription(User $user, TelegramGroup $group): void
    {
        $existing = $this->subscriptionRepository->findByUserAndGroup($user, $group);

        if (null !== $existing) {
            $this->em->remove($existing);
        } else {
            $this->em->persist(new UserGroupSubscription($user, $group));
        }

        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $callbackQuery
     */
    private function handlePrivMyChats(array $callbackQuery): void
    {
        $queryId = (string) ($callbackQuery['id'] ?? '');
        $message = $callbackQuery['message'] ?? [];
        $chat = \is_array($message) ? ($message['chat'] ?? []) : [];
        $chatType = \is_array($chat) ? (string) ($chat['type'] ?? '') : '';

        if ('private' !== $chatType) {
            $this->telegramClient->answerCallbackQuery($queryId);

            return;
        }

        $from = $callbackQuery['from'] ?? [];
        $user = $this->userUpsertService->findOrCreate($from);
        $chatId = (int) ($chat['id'] ?? 0);

        $this->telegramClient->answerCallbackQuery($queryId);

        if ($chatId > 0) {
            $this->privateChatHandler->sendMyChatsList($chatId, $user);
        }
    }

    /**
     * @param array<string, mixed> $callbackQuery
     */
    private function handlePrivHelp(array $callbackQuery): void
    {
        $queryId = (string) ($callbackQuery['id'] ?? '');
        $message = $callbackQuery['message'] ?? [];
        $chat = \is_array($message) ? ($message['chat'] ?? []) : [];
        $chatType = \is_array($chat) ? (string) ($chat['type'] ?? '') : '';

        if ('private' !== $chatType) {
            $this->telegramClient->answerCallbackQuery($queryId);

            return;
        }

        $chatId = (int) ($chat['id'] ?? 0);

        $this->telegramClient->answerCallbackQuery($queryId);

        if ($chatId > 0) {
            $this->privateChatHandler->sendHelpMessage($chatId);
        }
    }

    /**
     * Кнопка «Задать вопрос ИИ» в личке — то же, что команда /ask_ai без текста.
     *
     * @param array<string, mixed> $callbackQuery
     */
    private function handlePrivAskAi(array $callbackQuery): void
    {
        $queryId = (string) ($callbackQuery['id'] ?? '');
        $message = $callbackQuery['message'] ?? [];
        $chat = \is_array($message) ? ($message['chat'] ?? []) : [];
        $chatType = \is_array($chat) ? (string) ($chat['type'] ?? '') : '';

        if ('private' !== $chatType) {
            $this->telegramClient->answerCallbackQuery($queryId);

            return;
        }

        $from = $callbackQuery['from'] ?? [];
        if (!\is_array($from) || ($from['is_bot'] ?? false)) {
            $this->telegramClient->answerCallbackQuery($queryId);

            return;
        }

        $this->telegramClient->answerCallbackQuery($queryId);

        if (\is_array($message)) {
            $this->askAiTelegramService->handlePrivate($message, $from, '/ask_ai');
        }
    }
}
