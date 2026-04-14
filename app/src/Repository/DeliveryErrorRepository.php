<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DeliveryError;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeliveryError>
 */
class DeliveryErrorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeliveryError::class);
    }

    /**
     * Counts errors grouped by prefix ('ollama' or 'tg') for a given date.
     *
     * @return array{ollama: int, telegram: int}
     */
    public function countByPrefixForDate(\DateTimeImmutable $date, string $statsTimezone): array
    {
        $dateStr = $date->format('Y-m-d');

        /** @var array<array{prefix: string, cnt: string}> $rows */
        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(
                "SELECT
                    CASE WHEN error_type LIKE 'ollama%' THEN 'ollama' ELSE 'tg' END AS prefix,
                    COUNT(*) AS cnt
                FROM delivery_errors
                WHERE (occurred_at AT TIME ZONE 'UTC' AT TIME ZONE :stats_tz)::date = :date
                GROUP BY prefix",
                ['date' => $dateStr, 'stats_tz' => $statsTimezone],
            );

        $result = ['ollama' => 0, 'telegram' => 0];
        foreach ($rows as $row) {
            if ('ollama' === $row['prefix']) {
                $result['ollama'] = (int) $row['cnt'];
            } else {
                $result['telegram'] = (int) $row['cnt'];
            }
        }

        return $result;
    }

    /**
     * Returns recent error records for a given date, ordered by time desc.
     *
     * @return DeliveryError[]
     */
    public function findForDate(\DateTimeImmutable $date, string $statsTimezone, int $limit = 20): array
    {
        $tz = new \DateTimeZone($statsTimezone);
        $from = new \DateTimeImmutable($date->format('Y-m-d').'T00:00:00', $tz);
        $to = $from->modify('+1 day');

        return $this->createQueryBuilder('de')
            ->where('de.occurredAt >= :from')
            ->andWhere('de.occurredAt < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('de.occurredAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
