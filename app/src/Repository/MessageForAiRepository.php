<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MessageForAi;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MessageForAi>
 */
class MessageForAiRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageForAi::class);
    }

    /**
     * @return list<MessageForAi>
     */
    public function findAwaitingDeepSeek(int $limit = 100): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.responseText IS NULL AND m.errorMessage IS NULL')
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<MessageForAi>
     */
    public function findReadyToSend(int $limit = 100): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.sentAt IS NULL')
            ->andWhere('m.responseText IS NOT NULL OR m.errorMessage IS NOT NULL')
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Пользователи (telegram_user_id), у которых последняя активность ask_ai в личке раньше $beforeUtc.
     * Личка: chat_id > 0 и совпадает с telegram_user_id.
     *
     * @return list<int>
     */
    public function findTelegramUserIdsPrivateAiLastActivityBefore(\DateTimeImmutable $beforeUtc): array
    {
        $sql = <<<'SQL'
            SELECT m.telegram_user_id
            FROM message_for_ai m
            WHERE m.chat_id > 0 AND m.telegram_user_id = m.chat_id
            GROUP BY m.telegram_user_id
            HAVING MAX(m.created_at) < :before
            SQL;

        $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn($sql, [
            'before' => $beforeUtc->format('Y-m-d H:i:s'),
        ]);

        return array_map(static fn (mixed $v): int => (int) $v, $rows);
    }
}
