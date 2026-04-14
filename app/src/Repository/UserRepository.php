<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * @param list<int> $telegramUserIds
     *
     * @return list<User>
     */
    public function findEligibleForAiPrivateReengage(array $telegramUserIds, \DateTimeImmutable $cooldownBeforeUtc): array
    {
        if ([] === $telegramUserIds) {
            return [];
        }

        return $this->createQueryBuilder('u')
            ->where('u.telegramUserId IN (:ids)')
            ->andWhere('u.lastAiReengageSentAt IS NULL OR u.lastAiReengageSentAt < :cooldown')
            ->setParameter('ids', $telegramUserIds)
            ->setParameter('cooldown', $cooldownBeforeUtc)
            ->getQuery()
            ->getResult();
    }
}
