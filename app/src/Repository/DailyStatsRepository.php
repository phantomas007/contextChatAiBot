<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DailyStats;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DailyStats>
 */
class DailyStatsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DailyStats::class);
    }

    public function findByDate(\DateTimeImmutable $date): ?DailyStats
    {
        return $this->findOneBy(['statDate' => $date]);
    }
}
