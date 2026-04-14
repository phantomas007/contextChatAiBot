<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Repository\TelegramGroupRepository;
use App\Service\TelegramBotClient;

/**
 * Обработка callback_query с префиксом ga_ (панель администратора группы).
 */
final class GroupAdminCallbackHandler
{
    public function __construct(
        private readonly TelegramGroupRepository $groupRepository,
        private readonly TelegramBotClient $telegramClient,
        private readonly GroupAdminPanelService $adminPanel,
    ) {
    }

    /**
     * @param array<string, mixed> $callbackQuery
     */
    public function handle(array $callbackQuery): void
    {
        $queryId = (string) ($callbackQuery['id'] ?? '');
        $data = (string) ($callbackQuery['data'] ?? '');
        $message = $callbackQuery['message'] ?? [];
        if (!\is_array($message)) {
            $message = [];
        }
        $messageThreadId = TelegramBotClient::messageThreadIdFromMessage($message);
        $chat = $message['chat'] ?? [];
        if (!\is_array($chat)) {
            $chat = [];
        }

        $chatId = (int) ($chat['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);
        $chatType = (string) ($chat['type'] ?? '');

        if (!\in_array($chatType, ['group', 'supergroup'], true)) {
            $this->telegramClient->answerCallbackQuery($queryId, 'Только для групп', true);

            return;
        }

        $userId = (int) ($callbackQuery['from']['id'] ?? 0);

        if (!$this->telegramClient->isAdminInChat($chatId, $userId)) {
            $this->telegramClient->answerCallbackQuery($queryId, 'Только для администраторов группы.', true);

            return;
        }

        $group = $this->groupRepository->findOneBy(['telegramChatId' => $chatId]);

        if (null === $group) {
            $this->telegramClient->answerCallbackQuery($queryId, 'Группа не найдена в боте.', true);

            return;
        }

        if ('ga_close' === $data) {
            $this->telegramClient->removeInlineKeyboardFromMessage($chatId, $messageId, $messageThreadId);
            $this->telegramClient->answerCallbackQuery($queryId, 'Панель закрыта');

            return;
        }

        if ('ga_noop' === $data) {
            $this->telegramClient->answerCallbackQuery($queryId);

            return;
        }

        if (preg_match('/^ga_b_(\d+)$/', $data, $m)) {
            $ok = $this->adminPanel->applySetBatch($group, (int) $m[1]);

            $this->telegramClient->answerCallbackQuery(
                $queryId,
                $ok ? 'Сохранено' : 'Неверное значение',
                !$ok,
            );

            if ($ok && $messageId > 0) {
                $this->adminPanel->refreshPanelMessage($chatId, $messageId, $group, $messageThreadId);
            }

            return;
        }

        if ('ga_dis_cust' === $data) {
            $this->adminPanel->applyDisableCustom($group);
            $this->telegramClient->answerCallbackQuery($queryId, 'Публикация по числу выключена');

            if ($messageId > 0) {
                $this->adminPanel->refreshPanelMessage($chatId, $messageId, $group, $messageThreadId);
            }

            return;
        }

        if ('ga_en_daily' === $data) {
            $this->adminPanel->applyEnableDaily($group);
            $this->telegramClient->answerCallbackQuery($queryId, 'Суточный обзор включён');

            if ($messageId > 0) {
                $this->adminPanel->refreshPanelMessage($chatId, $messageId, $group, $messageThreadId);
            }

            return;
        }

        if ('ga_dis_daily' === $data) {
            $this->adminPanel->applyDisableDaily($group);
            $this->telegramClient->answerCallbackQuery($queryId, 'Суточный обзор выключен');

            if ($messageId > 0) {
                $this->adminPanel->refreshPanelMessage($chatId, $messageId, $group, $messageThreadId);
            }

            return;
        }

        $this->telegramClient->answerCallbackQuery($queryId);
    }
}
