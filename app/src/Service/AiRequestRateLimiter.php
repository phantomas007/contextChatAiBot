<?php

declare(strict_types=1);

namespace App\Service;

use Predis\ClientInterface as PredisClientInterface;

/**
 * Лимиты запросов к ИИ в сутки (UTC): отдельно для лички и для группы ({@see MAX_PER_DAY_PRIVATE_DM}, {@see MAX_PER_DAY_GROUP}).
 */
final class AiRequestRateLimiter
{
    /** Максимум запросов к ИИ в личке с ботом на одного пользователя за сутки (UTC). */
    public const int MAX_PER_DAY_PRIVATE_DM = 7;

    /** Максимум запросов к ИИ в групповом чате на один chat_id за сутки (UTC). */
    public const int MAX_PER_DAY_GROUP = 3;

    public function __construct(
        private readonly PredisClientInterface $redis,
    ) {
    }

    /** Текущее число использованных запросов за сегодня (UTC), без инкремента. */
    public function getPrivateDmUsageToday(int $telegramUserId): int
    {
        return $this->currentCount($this->dayKey('dm', $telegramUserId));
    }

    /** Текущее число использованных запросов группы за сегодня (UTC), без инкремента. */
    public function getGroupUsageToday(int $chatId): int
    {
        return $this->currentCount($this->dayKey('grp', $chatId));
    }

    private function currentCount(string $key): int
    {
        $v = $this->redis->get($key);

        return null === $v ? 0 : (int) $v;
    }

    public function allowPrivateDm(int $telegramUserId): bool
    {
        $key = $this->dayKey('dm', $telegramUserId);

        return $this->incrementAndCheck($key, self::MAX_PER_DAY_PRIVATE_DM);
    }

    public function allowGroup(int $chatId): bool
    {
        $key = $this->dayKey('grp', $chatId);

        return $this->incrementAndCheck($key, self::MAX_PER_DAY_GROUP);
    }

    private function dayKey(string $prefix, int $id): string
    {
        $day = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');

        return \sprintf('ai:%s:%s:%d', $prefix, $day, $id);
    }

    private function incrementAndCheck(string $key, int $maxPerDay): bool
    {
        $ttl = $this->secondsUntilUtcMidnight();

        $n = (int) $this->redis->incr($key);
        if (1 === $n) {
            $this->redis->expire($key, max(60, $ttl));
        }

        if ($n > $maxPerDay) {
            $this->redis->decr($key);

            return false;
        }

        return true;
    }

    private function secondsUntilUtcMidnight(): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $midnight = $now->modify('tomorrow')->setTime(0, 0, 0);

        return max(1, $midnight->getTimestamp() - $now->getTimestamp());
    }
}
