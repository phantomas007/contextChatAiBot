<?php

declare(strict_types=1);

namespace App\Enum;

enum ContextType: string
{
    case COUNT_50 = 'count_50';
    case COUNT_100 = 'count_100';
    case COUNT_150 = 'count_150';
    case COUNT_200 = 'count_200';
    case COUNT_300 = 'count_300';
    case DAILY = 'daily';

    public static function fromCount(int $threshold): self
    {
        return self::from('count_'.$threshold);
    }

    /**
     * Возвращает пороговое количество сообщений для count-based типов.
     * Для DAILY возвращает null.
     */
    public function getThresholdCount(): ?int
    {
        if (self::DAILY === $this) {
            return null;
        }

        return (int) str_replace('count_', '', $this->value);
    }
}
