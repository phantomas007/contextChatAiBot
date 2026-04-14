<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MessageForAi;
use App\Message\MessageForAiDeepSeekMessage;
use App\Telegram\AskAiGroupPendingStore;
use App\Telegram\AskAiPrivateModeStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Принимает /ask_ai в личке и группах, пишет только в message_for_ai (не в messages).
 *
 * В личке: /ask_ai без текста включает «режим диалога с ИИ» ({@see AskAiPrivateModeStore});
 * обычный текст уходит в ИИ только пока режим включён ({@see PrivateChatHandler}).
 * В группе: /ask_ai без текста ставит ожидание вопроса от этого участника ({@see AskAiGroupPendingStore};
 * перезапись при новом /ask_ai); следующее сообщение только от него уходит в ИИ; лимит общий на чат.
 * Обычный текст в группе всегда сохраняется в messages ({@see TelegramWebhookController}).
 *
 * Лимиты (UTC): {@see AiRequestRateLimiter::MAX_PER_DAY_PRIVATE_DM} в личке, {@see AiRequestRateLimiter::MAX_PER_DAY_GROUP} в группе.
 */
final class AskAiTelegramService
{
    public function __construct(
        private readonly TelegramBotClient $telegramClient,
        private readonly DeepSeekClient $deepSeekClient,
        private readonly AiRequestRateLimiter $rateLimiter,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly AskAiPrivateModeStore $askAiPrivateModeStore,
        private readonly AskAiGroupPendingStore $askAiGroupPendingStore,
    ) {
    }

    /**
     * Личка.
     *
     * @param array<string, mixed> $message
     * @param array<string, mixed> $from
     */
    public function handlePrivate(array $message, array $from, string $text): void
    {
        $this->handleAskAiMessage($message, $from, $text, isGroup: false);
    }

    /**
     * Группа: команда /ask_ai (с текстом или без).
     *
     * @param array<string, mixed> $message
     * @param array<string, mixed> $from
     */
    public function handleGroup(array $message, array $from, string $text): void
    {
        $this->handleAskAiMessage($message, $from, $text, isGroup: true);
    }

    /**
     * Группа: обычный текст — вопрос к ИИ только если этот пользователь в ожидании после /ask_ai.
     *
     * @param array<string, mixed> $message
     * @param array<string, mixed> $from
     */
    public function handleGroupPlainTextPending(array $message, array $from, string $text): void
    {
        $chatId = (int) $message['chat']['id'];
        $telegramUserId = (int) $from['id'];
        $pending = $this->askAiGroupPendingStore->getPendingUserId($chatId);
        if (null === $pending || $pending !== $telegramUserId) {
            return;
        }

        $trimmed = trim($text);
        if ('' === $trimmed) {
            return;
        }

        if (!$this->deepSeekClient->isConfigured()) {
            $this->askAiGroupPendingStore->clear($chatId);
            $this->telegramClient->sendMessage(
                $chatId,
                'ИИ временно недоступен (не задан ключ API). Нажмите /ask_ai ещё раз после настройки.',
            );

            return;
        }

        if (!$this->rateLimiter->allowGroup($chatId)) {
            $this->askAiGroupPendingStore->clear($chatId);
            $this->telegramClient->sendMessage(
                $chatId,
                \sprintf(
                    'Лимит запросов к ИИ в этом чате: не больше %d в сутки на группу. Попробуйте завтра.',
                    AiRequestRateLimiter::MAX_PER_DAY_GROUP,
                ),
            );

            return;
        }

        $this->askAiGroupPendingStore->clear($chatId);
        $this->persistAndAck($message, $telegramUserId, $chatId, $trimmed);
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $from
     */
    private function handleAskAiMessage(array $message, array $from, string $text, bool $isGroup): void
    {
        $chatId = (int) $message['chat']['id'];
        $telegramUserId = (int) $from['id'];

        $prompt = $this->extractPrompt($text);
        if (null === $prompt) {
            if (!$isGroup) {
                if (!$this->deepSeekClient->isConfigured()) {
                    $this->telegramClient->sendMessage(
                        $chatId,
                        'ИИ временно недоступен (не задан ключ API). Режим диалога не включён.',
                    );

                    return;
                }

                $this->askAiPrivateModeStore->enable($telegramUserId);
                $this->telegramClient->sendMessage($chatId, $this->buildPrivateModeOnMessage($telegramUserId));

                return;
            }

            if (!$this->deepSeekClient->isConfigured()) {
                $this->telegramClient->sendMessage(
                    $chatId,
                    'ИИ временно недоступен (не задан ключ API).',
                );

                return;
            }

            $this->askAiGroupPendingStore->setPending($chatId, $telegramUserId);
            $this->telegramClient->sendMessage($chatId, $this->buildGroupAskNextMessage($from));

            return;
        }

        if ($isGroup) {
            $this->askAiGroupPendingStore->clear($chatId);
        }

        if (!$this->deepSeekClient->isConfigured()) {
            $this->telegramClient->sendMessage(
                $chatId,
                'ИИ временно недоступен (не задан ключ API).',
            );

            return;
        }

        if ($isGroup) {
            if (!$this->rateLimiter->allowGroup($chatId)) {
                $this->telegramClient->sendMessage(
                    $chatId,
                    \sprintf(
                        'Лимит запросов к ИИ в этом чате: не больше %d в сутки на группу. Попробуйте завтра.',
                        AiRequestRateLimiter::MAX_PER_DAY_GROUP,
                    ),
                );

                return;
            }
        } else {
            if (!$this->rateLimiter->allowPrivateDm($telegramUserId)) {
                $this->telegramClient->sendMessage(
                    $chatId,
                    \sprintf(
                        'Лимит запросов к ИИ в личке: не больше %d в сутки. Попробуй завтра.',
                        AiRequestRateLimiter::MAX_PER_DAY_PRIVATE_DM,
                    ),
                );

                return;
            }
        }

        if (!$isGroup) {
            $this->askAiPrivateModeStore->enable($telegramUserId);
        }

        $this->persistAndAck($message, $telegramUserId, $chatId, $prompt);
    }

    /**
     * @param array<string, mixed> $from
     */
    private function buildGroupAskNextMessage(array $from): string
    {
        $uid = (int) ($from['id'] ?? 0);
        $username = $from['username'] ?? null;
        $name = \is_string($username) && '' !== $username
            ? '@'.$username
            : (string) ($from['first_name'] ?? 'участник');
        $nameEscaped = htmlspecialchars($name, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return <<<HTML
            💬 Теперь <a href="tg://user?id={$uid}">{$nameEscaped}</a>, задайте вопрос ИИ <b>следующим сообщением</b> в этом чате.
            HTML;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function persistAndAck(array $message, int $telegramUserId, int $chatId, string $prompt): void
    {
        $replyTo = (int) $message['message_id'];
        $threadId = TelegramBotClient::messageThreadIdFromMessage($message);

        $row = new MessageForAi(
            $telegramUserId,
            $chatId,
            $replyTo,
            $prompt,
            $threadId,
        );

        $this->em->persist($row);
        $this->em->flush();

        $id = $row->getId();
        if (null !== $id) {
            $this->bus->dispatch(new MessageForAiDeepSeekMessage($id));
        }

        $this->telegramClient->sendMessageReply(
            $chatId,
            'Запрос принят, ответ придёт отдельным сообщением.',
            $replyTo,
            $threadId,
        );
    }

    private function buildPrivateModeOnMessage(int $telegramUserId): string
    {
        $used = $this->rateLimiter->getPrivateDmUsageToday($telegramUserId);
        $max = AiRequestRateLimiter::MAX_PER_DAY_PRIVATE_DM;
        $remaining = max(0, $max - $used);

        return <<<HTML
            ✅ <b>Режим диалога с ИИ включён</b>

            💬 Пишите вопросы прямо в чат

            💡 <b>Подсказки</b>
            • Формулируйте вопрос конкретно — ответ будет точнее
            • Можно спрашивать: справку, советы, идеи, код

            ━━━━━━━━━━━━━━━
            🚪 Выйти из режима → /stop_ask_ai

            📊 <b>Запросов сегодня (UTC)</b>
            <code>{$used} из {$max} • Осталось: {$remaining}</code>
            HTML;
    }

    private function extractPrompt(string $text): ?string
    {
        $trimmed = trim($text);
        if ('' === $trimmed) {
            return null;
        }

        $first = explode(' ', $trimmed, 2)[0];
        $first = preg_replace('/@[A-Za-z0-9_]+$/', '', $first) ?? $first;
        if (!str_starts_with($first, '/ask_ai')) {
            return null;
        }

        $parts = preg_split('/\s+/', $trimmed, 2);

        return isset($parts[1]) ? trim($parts[1]) : null;
    }
}
