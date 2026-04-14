<?php

declare(strict_types=1);

namespace App\Telegram;

use Predis\ClientInterface as PredisClientInterface;

/**
 * Режим «диалог с ИИ» в личке с ботом: пока включён, обычный текст уходит в message_for_ai.
 */
final class AskAiPrivateModeStore
{
    private const string KEY_PREFIX = 'ai:priv_ask_mode:';

    public function __construct(
        private readonly PredisClientInterface $redis,
    ) {
    }

    public function isEnabled(int $telegramUserId): bool
    {
        return '1' === (string) $this->redis->get($this->key($telegramUserId));
    }

    public function enable(int $telegramUserId): void
    {
        $this->redis->set($this->key($telegramUserId), '1');
    }

    public function disable(int $telegramUserId): void
    {
        $this->redis->del([$this->key($telegramUserId)]);
    }

    private function key(int $telegramUserId): string
    {
        return self::KEY_PREFIX.$telegramUserId;
    }
}
