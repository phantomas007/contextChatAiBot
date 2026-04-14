<?php

declare(strict_types=1);

namespace App\Summary;

/**
 * Сколько сообщений чата уходит в один кирпич (Context) при саммаризации.
 * Пороги /set_batch должны быть кратны этому числу — см. CheckAggregationGroupHandler.
 */
final class SummaryBrickSize
{
    public const MESSAGES_PER_BRICK = 50;

    private function __construct()
    {
    }
}
