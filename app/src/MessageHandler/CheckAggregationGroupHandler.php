<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\ContextType;
use App\Message\CheckAggregationMessage;
use App\Repository\GroupSettingsRepository;
use App\Repository\TelegramGroupRepository;
use App\Service\AggregationService;
use App\Summary\SummaryBrickSize;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Проверяет пороги count-based агрегации для группы и сохраняет агрегированный контекст.
 *
 * Обрабатывает CheckAggregationMessage из очереди aggregation_checks.
 * Сообщения диспатчатся cron-командой app:check-aggregation-group (каждые 3 мин).
 * Если накопилось достаточно кирпичей по настройке группы — вызывает AggregationService::buildAndPersist,
 * который сохраняет AggregatedGroupContext в aggregated_contexts_for_group.
 * Публикацию в Telegram выполняет app:publish-group-contexts.
 *
 * Lock предотвращает состояние гонки: если несколько воркеров одновременно обрабатывают
 * CheckAggregationMessage для одной группы, только один выполнит агрегацию.
 */
#[AsMessageHandler]
final class CheckAggregationGroupHandler
{
    public function __construct(
        private readonly TelegramGroupRepository $groupRepository,
        private readonly GroupSettingsRepository $groupSettingsRepository,
        private readonly AggregationService $aggregationService,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function __invoke(CheckAggregationMessage $message): void
    {
        $group = $this->groupRepository->find($message->groupId);
        if (null === $group) {
            return;
        }

        $lock = $this->lockFactory->createLock(
            'check_aggregation_group_'.$message->groupId,
            ttl: 300,
            autoRelease: false,
        );

        if (!$lock->acquire()) {
            return;
        }

        try {
            $settings = $this->groupSettingsRepository->findByGroup($group);

            if (null === $settings || null === $settings->getCountThreshold()) {
                return;
            }

            $threshold = $settings->getCountThreshold();
            $type = ContextType::fromCount($threshold);
            $neededBricks = (int) ($threshold / SummaryBrickSize::MESSAGES_PER_BRICK);
            $allBricks = $this->aggregationService->getNewBricksForGroup($group, $type);

            if (\count($allBricks) >= $neededBricks) {
                // Берём ровно столько кирпичей, сколько нужно для одной агрегации.
                // Курсор (periodTo) продвинется на эти кирпичи; следующий запуск подхватит остаток.
                $bricks = \array_slice($allBricks, 0, $neededBricks);
                $this->aggregationService->buildAndPersist($group, $bricks, $type);
            }

            // Подписки пользователей (count-based) — будет реализовано отдельной командой.
            // Логика: проверяет aggregated_contexts_for_group на совпадение настроек,
            // при необходимости создаёт aggregated_contexts_for_user из кирпичей.
            // $subscriptions = $this->subscriptionRepository->findCountBasedByGroup($group);
            // foreach ($subscriptions as $sub) { ... }
        } finally {
            $lock->release();
        }
    }
}
