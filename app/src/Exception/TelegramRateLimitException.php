<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when the Telegram Bot API returns 429 (Too Many Requests).
 * Symfony Messenger will retry the job automatically via the configured retry strategy.
 */
final class TelegramRateLimitException extends \RuntimeException
{
    public function __construct(int $retryAfter)
    {
        parent::__construct(\sprintf('Telegram rate limit exceeded, retry after %d seconds', $retryAfter));
    }
}
