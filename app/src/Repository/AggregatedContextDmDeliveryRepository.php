<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AggregatedContextDmDelivery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AggregatedContextDmDelivery>
 */
class AggregatedContextDmDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AggregatedContextDmDelivery::class);
    }

    /**
     * Пары (aggregated_group_context_id, user_id), для которых ещё нет строки доставки.
     * Условия: агрегат уже опубликован в группе, есть подписка, пользователь может писать боту в ЛС.
     *
     * @return list<array{aggId: int, userId: int}>
     */
    public function findNewDispatchPairs(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<'SQL'
            SELECT a.id AS agg_id, u.id AS user_id
            FROM aggregated_contexts_for_group a
            INNER JOIN user_group_subscriptions s ON s.group_id = a.group_id
            INNER JOIN users u ON u.id = s.user_id
            WHERE a.sent_at IS NOT NULL
              AND u.has_bot_chat = TRUE
              AND NOT EXISTS (
                  SELECT 1 FROM aggregated_context_dm_deliveries d
                  WHERE d.aggregated_group_context_id = a.id AND d.user_id = u.id
              )
            ORDER BY a.id ASC, u.id ASC
            SQL;

        /** @var list<array{agg_id: string, user_id: string}> $rows */
        $rows = $conn->fetchAllAssociative($sql);

        $out = [];
        foreach ($rows as $row) {
            $out[] = ['aggId' => (int) $row['agg_id'], 'userId' => (int) $row['user_id']];
        }

        return $out;
    }
}
