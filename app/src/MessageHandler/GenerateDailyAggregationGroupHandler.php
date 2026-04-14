<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\ContextType;
use App\Message\GenerateDailyAggregationMessage;
use App\Repository\AggregatedContextGroupRepository;
use App\Repository\TelegramGroupRepository;
use App\Service\AggregationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Генерирует суточный агрегированный контекст для группы.
 *
 * Обрабатывает GenerateDailyAggregationMessage из очереди aggregation_checks.
 * Сообщения диспатчатся командой app:generate-daily-aggregation-group (23:50).
 *
 * Кирпичи отбираются через cursor-механизм (periodTo последнего дейли) — это
 * автоматически включает "хвост" предыдущего дня, если он не уложился в лимит.
 * Суммарный messages_count ограничен MAX_DAILY_MESSAGES: оставшиеся кирпичи
 * попадут в следующий суточный прогон.
 *
 * Lock предотвращает состояние гонки при параллельной обработке одной группы.
 * Проверка findDailyForToday обеспечивает идемпотентность: если сообщение
 * задиспатчено дважды или обработчик перезапустился, второй запуск будет no-op.
 * Публикацию в Telegram-группу выполняет app:publish-group-contexts.
 */
#[AsMessageHandler]
final class GenerateDailyAggregationGroupHandler
{
    private const int MAX_DAILY_MESSAGES = 1500;

    public function __construct(
        private readonly TelegramGroupRepository $groupRepository,
        private readonly AggregatedContextGroupRepository $aggregatedContextGroupRepository,
        private readonly AggregationService $aggregationService,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateDailyAggregationMessage $message): void
    {
        $lock = $this->lockFactory->createLock(
            'daily_aggregation_group_'.$message->groupId,
            ttl: 600,
            autoRelease: false,
        );

        if (!$lock->acquire()) {
            return;
        }

        try {
            $group = $this->groupRepository->find($message->groupId);
            if (null === $group) {
                return;
            }

            // Idempotency guard: суточный контекст уже создан сегодня
            if (null !== $this->aggregatedContextGroupRepository->findDailyForToday($group)) {
                $this->logger->info('GenerateDailyAggregationGroupHandler: суточный контекст уже существует', [
                    'group_id' => $message->groupId,
                ]);

                return;
            }

            $allBricks = $this->aggregationService->getNewBricksForGroup($group, ContextType::DAILY);

            if (empty($allBricks)) {
                $this->logger->info('GenerateDailyAggregationGroupHandler: новых кирпичей нет', [
                    'group_id' => $message->groupId,
                ]);

                return;
            }

            // Берём кирпичи пока кумулятивная сумма messages_count не превысит лимит.
            // Минимум 1 кирпич — на случай если один кирпич сам по себе превышает лимит.
            // Оставшиеся кирпичи попадут в следующий суточный прогон через cursor-механизм.
            $bricks = [];
            $total = 0;
            foreach ($allBricks as $brick) {
                $newTotal = $total + $brick->getMessagesCount();
                if (!empty($bricks) && $newTotal > self::MAX_DAILY_MESSAGES) {
                    break;
                }
                $bricks[] = $brick;
                $total = $newTotal;
            }

            $this->logger->info('GenerateDailyAggregationGroupHandler: кирпичи отобраны с лимитом', [
                'group_id' => $message->groupId,
                'available_bricks' => \count($allBricks),
                'selected_bricks' => \count($bricks),
                'messages_total' => $total,
                'limit' => self::MAX_DAILY_MESSAGES,
            ]);

            $aggregated = $this->aggregationService->buildAndPersist($group, $bricks, ContextType::DAILY);

            $this->logger->info('GenerateDailyAggregationGroupHandler: суточный контекст создан', [
                'group_id' => $message->groupId,
                'aggregated_context_id' => $aggregated->getIdOrFail(),
                'bricks_count' => $aggregated->getBricksCount(),
                'messages_count' => $aggregated->getMessagesCount(),
            ]);
        } finally {
            $lock->release();
        }
    }
}
