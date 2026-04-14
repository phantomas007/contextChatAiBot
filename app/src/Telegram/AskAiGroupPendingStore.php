<?php

declare(strict_types=1);

namespace App\Telegram;

use Predis\ClientInterface as PredisClientInterface;

/**
 * В группе после /ask_ai ожидается вопрос от одного участника (последний нажавший /ask_ai перезаписывает).
 */
final class AskAiGroupPendingStore
{
    private const string KEY_PREFIX = 'ai:grp_ask_pending:';

    private const int TTL_SECONDS = 900;

    public function __construct(
        private readonly PredisClientInterface $redis,
    ) {
    }

    public function setPending(int $chatId, int $telegramUserId): void
    {
        $key = $this->key($chatId);
        $this->redis->set($key, (string) $telegramUserId);
        $this->redis->expire($key, self::TTL_SECONDS);
    }

    public function getPendingUserId(int $chatId): ?int
    {
        $v = $this->redis->get($this->key($chatId));
        if (null === $v || '' === (string) $v) {
            return null;
        }

        return (int) $v;
    }

    public function clear(int $chatId): void
    {
        $this->redis->del([$this->key($chatId)]);
    }

    private function key(int $chatId): string
    {
        return self::KEY_PREFIX.$chatId;
    }
}
