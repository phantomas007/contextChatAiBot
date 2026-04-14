<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SummaryJobMessage
{
    public function __construct(
        public int $groupId,
    ) {
    }
}
