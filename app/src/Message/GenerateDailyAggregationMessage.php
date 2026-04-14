<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Задание на генерацию суточного агрегированного контекста для группы.
 * Диспатчится командой app:generate-daily-aggregation-group (23:50 каждую ночь).
 * Обрабатывается GenerateDailyAggregationGroupHandler из очереди aggregation_checks.
 */
final class GenerateDailyAggregationMessage
{
    public function __construct(
        public readonly int $groupId,
    ) {
    }
}
