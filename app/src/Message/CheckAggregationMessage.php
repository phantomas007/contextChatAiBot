<?php

declare(strict_types=1);

namespace App\Message;

final readonly class CheckAggregationMessage
{
    public function __construct(
        public int $groupId,
    ) {
    }
}
