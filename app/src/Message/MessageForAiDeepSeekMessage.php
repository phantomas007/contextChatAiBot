<?php

declare(strict_types=1);

namespace App\Message;

final readonly class MessageForAiDeepSeekMessage
{
    public function __construct(
        public int $messageForAiId,
    ) {
    }
}
