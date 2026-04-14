<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Repository\TelegramGroupRepository;
use App\Service\AskAiTelegramService;
use App\Service\TelegramBotClient;
use App\Service\UserUpsertService;

/**
 * Обрабатывает команды бота в групповых чатах.
 *
 * /ask_ai — ожидание вопроса от нажавшего (Redis); лимит на группу — {@see AskAiTelegramService}.
 * /group_settings — панель админа с inline-кнопками; настройки только через кнопки.
 */
final class GroupChatHandler
{
    public function __construct(
        private readonly TelegramGroupRepository $groupRepository,
        private readonly TelegramBotClient $telegramClient,
        private readonly UserUpsertService $userUpsertService,
        private readonly GroupAdminPanelService $adminPanel,
        private readonly AskAiTelegramService $askAiTelegramService,
    ) {
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $from
     */
    public function handle(array $message, array $from, string $text): void
    {
        $chatId = (int) $message['chat']['id'];
        $userId = (int) $from['id'];
        $this->userUpsertService->findOrCreate($from);

        $cmd = $this->normalizedCommandToken($text);
        // До проверки isAdminInChat: /ask_ai может любой участник группы (лимит — в AskAiTelegramService).
        if (str_starts_with($cmd, '/ask_ai')) {
            $this->askAiTelegramService->handleGroup($message, $from, $text);

            return;
        }

        if ($this->isAdminOnlyCommand($text) && !$this->telegramClient->isAdminInChat($chatId, $userId)) {
            $this->telegramClient->sendMessage(
                $chatId,
                '⚠️ Эта команда только для администраторов группы.',
            );

            return;
        }

        if (!$this->telegramClient->isAdminInChat($chatId, $userId)) {
            return;
        }

        $group = $this->groupRepository->findOneBy(['telegramChatId' => $chatId]);
        if (null === $group) {
            return;
        }

        if (str_starts_with($text, '/group_settings')) {
            $this->adminPanel->sendPanel($chatId, $group);
        }
    }

    /**
     * Обычный текст: в ИИ только если пользователь в ожидании после /ask_ai ({@see AskAiTelegramService::handleGroupPlainTextPending}).
     * Сообщение всегда сохраняется в messages через очередь ({@see TelegramWebhookController}).
     *
     * @param array<string, mixed> $message
     * @param array<string, mixed> $from
     */
    public function handlePlainTextAsAskAi(array $message, array $from, string $text): void
    {
        $this->userUpsertService->findOrCreate($from);
        $trimmed = trim($text);
        if ('' === $trimmed) {
            return;
        }

        $this->askAiTelegramService->handleGroupPlainTextPending($message, $from, $trimmed);
    }

    /**
     * Учитывает вид /command@BotName.
     */
    private function isAdminOnlyCommand(string $text): bool
    {
        $first = explode(' ', trim($text), 2)[0];
        $first = preg_replace('/@[A-Za-z0-9_]+$/', '', $first) ?? $first;

        return str_starts_with($first, '/group_settings');
    }

    private function normalizedCommandToken(string $text): string
    {
        $first = explode(' ', trim($text), 2)[0];

        return preg_replace('/@[A-Za-z0-9_]+$/', '', $first) ?? $first;
    }
}
