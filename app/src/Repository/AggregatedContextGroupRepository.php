<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AggregatedGroupContext;
use App\Entity\TelegramGroup;
use App\Enum\ContextType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AggregatedGroupContext>
 */
class AggregatedContextGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AggregatedGroupContext::class);
    }

    /**
     * Последний агрегированный контекст данного типа для группы.
     * Используется как курсор: кирпичи после его period_to — ещё не агрегированы.
     */
    public function findLastByType(TelegramGroup $group, ContextType $type): ?AggregatedGroupContext
    {
        return $this->createQueryBuilder('ac')
            ->where('ac.group = :group')
            ->andWhere('ac.settingsType = :type')
            ->setParameter('group', $group)
            ->setParameter('type', $type)
            ->orderBy('ac.periodTo', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Суточный контекст для группы за сегодня (с 00:00 текущего дня).
     * Используется для идемпотентности GenerateDailyAggregationForGroupCommand.
     */
    public function findDailyForToday(TelegramGroup $group): ?AggregatedGroupContext
    {
        return $this->createQueryBuilder('ac')
            ->where('ac.group = :group')
            ->andWhere('ac.settingsType = :type')
            ->andWhere('ac.createdAt >= :todayStart')
            ->setParameter('group', $group)
            ->setParameter('type', ContextType::DAILY)
            ->setParameter('todayStart', new \DateTimeImmutable('today midnight'))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Последняя count-based агрегация любого типа для группы.
     * Используется как fallback-курсор при смене countThreshold: новый тип не имеет
     * истории, но начинать с epoch 0 неправильно — нужно продолжить с того места,
     * где остановился предыдущий тип.
     */
    public function findLastCountBased(TelegramGroup $group): ?AggregatedGroupContext
    {
        return $this->createQueryBuilder('ac')
            ->where('ac.group = :group')
            ->andWhere('ac.settingsType != :daily')
            ->setParameter('group', $group)
            ->setParameter('daily', ContextType::DAILY)
            ->orderBy('ac.periodTo', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Агрегации, которые ещё не отправлены и не стоят в очереди на публикацию.
     * dispatchedAt IS NULL предотвращает повторный диспатч одной и той же записи
     * при каждом запуске app:publish-group-contexts (каждую минуту).
     *
     * @return AggregatedGroupContext[]
     */
    public function findUnsent(): array
    {
        return $this->createQueryBuilder('ac')
            ->where('ac.sentAt IS NULL')
            ->andWhere('ac.dispatchedAt IS NULL')
            ->orderBy('ac.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
