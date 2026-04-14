<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Exception\BotBlockedByUserException;
use App\Exception\TelegramRateLimitException;
use App\Message\PublishGroupJobMessage;
use App\Repository\AggregatedContextGroupRepository;
use App\Service\AggregationService;
use App\Service\DeliveryErrorLogger;
use App\Service\TelegramBotClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Публикует агрегированный контекст (count-based и daily) в Telegram-группу.
 *
 * Обрабатывает PublishGroupJobMessage из очереди publish_group_jobs.
 * Сообщения диспатчатся командой app:publish-group-contexts (каждую минуту).
 *
 * Lock предотвращает состояние гонки при параллельной обработке одного и того же
 * aggregatedContextId несколькими воркерами.
 * Проверка sentAt IS NULL обеспечивает идемпотентность: если команда задиспатчила
 * одно сообщение дважды, второй запуск обработчика будет no-op.
 *
 * Рассылка того же агрегата в ЛС: app:dispatch-aggregated-dm-jobs → очередь publish_dm_jobs (PublishDmJobHandler).
 */
#[AsMessageHandler]
final class PublishGroupJobHandler
{
    public function __construct(
        private readonly AggregatedContextGroupRepository $aggregatedContextGroupRepository,
        private readonly AggregationService $aggregationService,
        private readonly TelegramBotClient $telegramClient,
        private readonly DeliveryErrorLogger $errorLogger,
        private readonly EntityManagerInterface $em,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PublishGroupJobMessage $message): void
    {
        $lock = $this->lockFactory->createLock(
            'publish_group_context_'.$message->aggregatedContextId,
            ttl: 60,
            autoRelease: false,
        );

        if (!$lock->acquire()) {
            return;
        }

        try {
            $aggregated = $this->aggregatedContextGroupRepository->find($message->aggregatedContextId);

            if (null === $aggregated) {
                return;
            }

            // Idempotency guard: если уже отправлено (например, сообщение задиспатчено дважды)
            if (null !== $aggregated->getSentAt()) {
                return;
            }

            $group = $aggregated->getGroup();
            $chatId = $group->getTelegramChatId();
            $text = $this->aggregationService->formatText($aggregated);

            try {
                $this->telegramClient->sendMessageWithKeyboard(
                    $chatId,
                    $text,
                    [
                        [
                            [
                                'text' => '🫥 Свернуть',
                                'callback_data' => 'ctx_toggle',
                            ],
                        ],
                    ],
                );
            } catch (BotBlockedByUserException $e) {
                $this->errorLogger->logTelegram(403, null, null, $chatId, $group->getTitle(), $e->getMessage());
                $this->logger->warning('PublishGroupJobHandler: бот удалён из группы', ['chat_id' => $chatId]);

                return;
            } catch (TelegramRateLimitException $e) {
                $this->errorLogger->logTelegram(429, null, null, $chatId, $group->getTitle(), $e->getMessage());
                throw $e;
            }

            $aggregated->markAsSent();
            $this->em->flush();

            $this->logger->info('PublishGroupJobHandler: контекст опубликован', [
                'aggregated_context_id' => $message->aggregatedContextId,
                'chat_id' => $chatId,
                'group_title' => $group->getTitle(),
            ]);
        } finally {
            $lock->release();
        }
    }
}
