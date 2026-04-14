<?php

declare(strict_types=1);

namespace App\Service;

use App\Telegram\BotMemberHandler;
use App\Telegram\CallbackQueryHandler;
use App\Telegram\GroupChatHandler;
use App\Telegram\PrivateChatHandler;

/**
 * Маршрутизирует входящие Telegram-обновления к специализированным обработчикам.
 */
class UpdateRouter
{
    public function __construct(
        private readonly PrivateChatHandler $privateChatHandler,
        private readonly GroupChatHandler $groupChatHandler,
        private readonly CallbackQueryHandler $callbackQueryHandler,
        private readonly BotMemberHandler $botMemberHandler,
    ) {
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        if (isset($update['my_chat_member'])) {
            $this->botMemberHandler->handle($update['my_chat_member']);

            return;
        }

        if (isset($update['callback_query'])) {
            $this->callbackQueryHandler->handle($update['callback_query']);

            return;
        }

        $message = $update['message'] ?? null;
        if (!\is_array($message)) {
            return;
        }

        $chatType = $message['chat']['type'] ?? '';
        $text = trim($message['text'] ?? '');
        $from = $message['from'] ?? null;

        if (!\is_array($from) || ($from['is_bot'] ?? false)) {
            return;
        }

        if ('private' === $chatType) {
            $this->privateChatHandler->handle($message, $from, $text);
        } elseif (\in_array($chatType, ['group', 'supergroup'], true)) {
            $this->groupChatHandler->handle($message, $from, $text);
        }
    }

    /**
     * Группа: не-команда — при необходимости вопрос к ИИ (только «ожидающий» участник); сохранение в messages — в контроллере.
     *
     * @param array<string, mixed> $update
     */
    public function handleGroupPlainTextForAi(array $update): void
    {
        $message = $update['message'] ?? null;
        if (!\is_array($message)) {
            return;
        }

        $text = trim($message['text'] ?? '');
        if ('' === $text || str_starts_with($text, '/')) {
            return;
        }

        $from = $message['from'] ?? null;
        if (!\is_array($from) || ($from['is_bot'] ?? false)) {
            return;
        }

        $this->groupChatHandler->handlePlainTextAsAskAi($message, $from, $text);
    }
}
