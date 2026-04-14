<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GroupSettings;
use App\Entity\TelegramGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GroupSettings>
 */
class GroupSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GroupSettings::class);
    }

    public function findByGroup(TelegramGroup $group): ?GroupSettings
    {
        return $this->findOneBy(['group' => $group]);
    }

    /**
     * Группы с настроенной публикацией по количеству сообщений.
     *
     * @return GroupSettings[]
     */
    public function findAllWithCountThreshold(): array
    {
        return $this->createQueryBuilder('gs')
            ->where('gs.countThreshold IS NOT NULL')
            ->getQuery()
            ->getResult();
    }
}
