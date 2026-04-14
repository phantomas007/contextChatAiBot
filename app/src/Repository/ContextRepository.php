<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Context;
use App\Entity\TelegramGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Context>
 */
class ContextRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Context::class);
    }

    /**
     * Кирпичи созданные сегодня (с 00:00 текущего дня).
     *
     * @return Context[]
     */
    public function findTodayBricks(TelegramGroup $group): array
    {
        $todayStart = new \DateTimeImmutable('today midnight');

        return $this->createQueryBuilder('c')
            ->where('c.group = :group')
            ->andWhere('c.createdAt >= :todayStart')
            ->setParameter('group', $group)
            ->setParameter('todayStart', $todayStart)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Кирпичи созданные после указанного момента.
     *
     * @return Context[]
     */
    public function findAfter(TelegramGroup $group, \DateTimeImmutable $after): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.group = :group')
            ->andWhere('c.periodTo > :after')
            ->setParameter('group', $group)
            ->setParameter('after', $after)
            ->orderBy('c.periodTo', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
