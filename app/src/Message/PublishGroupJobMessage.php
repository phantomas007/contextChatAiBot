<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Задание на публикацию count-based агрегированного контекста в Telegram-группу.
 * Диспатчится командой app:publish-group-contexts.
 * Обрабатывается PublishGroupJobHandler из очереди publish_group_jobs.
 */
final class PublishGroupJobMessage
{
    public function __construct(
        public readonly int $aggregatedContextId,
    ) {
    }
}
