<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Message;
use App\Entity\TelegramGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    public function countUnsummarized(TelegramGroup $group): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.group = :group')
            ->andWhere('m.summarizedAt IS NULL')
            ->setParameter('group', $group)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Message[]
     */
    public function findUnsummarized(TelegramGroup $group, int $limit = 100): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.group = :group')
            ->andWhere('m.summarizedAt IS NULL')
            ->orderBy('m.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->setParameter('group', $group)
            ->getQuery()
            ->getResult();
    }
}
