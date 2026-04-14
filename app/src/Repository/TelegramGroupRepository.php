<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TelegramGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TelegramGroup>
 */
class TelegramGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TelegramGroup::class);
    }

    /**
     * @return TelegramGroup[]
     */
    public function findAllActive(): array
    {
        return $this->findBy(['isActive' => true]);
    }
}
