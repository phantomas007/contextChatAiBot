<?php

declare(strict_types=1);

namespace App\Message;

final readonly class IncomingTelegramUpdateMessage
{
    /** @param array<string, mixed> $update */
    public function __construct(
        public array $update,
    ) {
    }
}
