<?php

declare(strict_types=1);

namespace App\Telegram;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Полный текст сообщения с саммари при сворачивании — Telegram хранит только текущий текст,
 * поэтому перед сокращением сохраняем его в Redis (Symfony cache pool) и поднимаем по «Показать».
 */
final class ContextSummaryFoldCache
{
    private const string KEY_PREFIX = 'ctx_fold_';

    private const int TTL_SECONDS = 172800; // 2 суток (группы и личка)

    public function __construct(
        private readonly CacheItemPoolInterface $foldPool,
    ) {
    }

    public function save(int $chatId, int $messageId, string $fullText): void
    {
        $item = $this->foldPool->getItem($this->key($chatId, $messageId));
        $item->set($fullText);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->foldPool->save($item);
    }

    public function fetch(int $chatId, int $messageId): ?string
    {
        $item = $this->foldPool->getItem($this->key($chatId, $messageId));
        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();

        return \is_string($value) ? $value : null;
    }

    public function delete(int $chatId, int $messageId): void
    {
        $this->foldPool->deleteItem($this->key($chatId, $messageId));
    }

    private function key(int $chatId, int $messageId): string
    {
        return self::KEY_PREFIX.$chatId.'_'.$messageId;
    }
}
