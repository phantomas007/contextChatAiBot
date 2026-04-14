<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when the Telegram Bot API returns 403 (bot was blocked by the user).
 * The caller is responsible for updating the domain (User::markBotChatBlocked).
 */
final class BotBlockedByUserException extends \RuntimeException
{
    public function __construct(int $chatId)
    {
        parent::__construct(\sprintf('Bot is blocked by user/chat %d', $chatId));
    }
}
