<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TelegramGroup;
use App\Entity\User;
use App\Entity\UserGroupSubscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserGroupSubscription>
 */
class UserGroupSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserGroupSubscription::class);
    }

    public function findByUserAndGroup(User $user, TelegramGroup $group): ?UserGroupSubscription
    {
        return $this->findOneBy(['user' => $user, 'group' => $group]);
    }

    /**
     * Подписчики группы с count-based настройкой.
     *
     * @return UserGroupSubscription[]
     */
    public function findCountBasedByGroup(TelegramGroup $group): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.group = :group')
            ->andWhere('s.countThreshold IS NOT NULL')
            ->setParameter('group', $group)
            ->getQuery()
            ->getResult();
    }

    /**
     * Подписчики группы с daily-настройкой (time_interval = 1440).
     *
     * @return UserGroupSubscription[]
     */
    public function findDailyByGroup(TelegramGroup $group): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.group = :group')
            ->andWhere('s.timeInterval = 1440')
            ->setParameter('group', $group)
            ->getQuery()
            ->getResult();
    }

    /**
     * Все подписки пользователя.
     *
     * @return UserGroupSubscription[]
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user]);
    }
}
