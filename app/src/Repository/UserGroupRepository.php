<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TelegramGroup;
use App\Entity\User;
use App\Entity\UserGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserGroup>
 */
class UserGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserGroup::class);
    }

    /**
     * Активные группы, где пользователь есть в user_groups (запись при первом
     * сохранённом сообщении в чате — см. MessageSaveHandler::ensureUserGroup).
     *
     * @return TelegramGroup[]
     */
    public function findActiveTelegramGroupsForUser(User $user): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('g')
            ->from(TelegramGroup::class, 'g')
            ->innerJoin('g.userGroups', 'ug')
            ->where('ug.user = :user')
            ->andWhere('g.isActive = :active')
            ->setParameter('user', $user)
            ->setParameter('active', true)
            ->orderBy('g.title', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
