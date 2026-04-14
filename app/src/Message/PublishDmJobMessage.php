<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Отправка агрегата в ЛС по строке aggregated_context_dm_deliveries (фаза 2).
 * После INSERT строки «к отправке» диспатчится командой app:dispatch-aggregated-dm-jobs.
 */
final class PublishDmJobMessage
{
    public function __construct(
        public readonly int $dmDeliveryId,
    ) {
    }
}
