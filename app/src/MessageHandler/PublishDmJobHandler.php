<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Exception\BotBlockedByUserException;
use App\Exception\TelegramRateLimitException;
use App\Message\PublishDmJobMessage;
use App\Repository\AggregatedContextDmDeliveryRepository;
use App\Service\AggregationService;
use App\Service\DeliveryErrorLogger;
use App\Service\TelegramBotClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * Фаза 2: отправка текста агрегата в ЛС по существующей строке доставки.
 */
#[AsMessageHandler]
final class PublishDmJobHandler
{
    public function __construct(
        private readonly AggregatedContextDmDeliveryRepository $dmDeliveryRepository,
        private readonly AggregationService $aggregationService,
        private readonly TelegramBotClient $telegramClient,
        private readonly DeliveryErrorLogger $errorLogger,
        private readonly EntityManagerInterface $em,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PublishDmJobMessage $message): void
    {
        $lock = $this->lockFactory->createLock(
            'publish_dm_delivery_'.$message->dmDeliveryId,
            ttl: 120,
            autoRelease: false,
        );

        if (!$lock->acquire()) {
            return;
        }

        try {
            $delivery = $this->dmDeliveryRepository->find($message->dmDeliveryId);

            if (null === $delivery) {
                return;
            }

            if (!$delivery->isPending()) {
                return;
            }

            $aggregated = $delivery->getAggregatedGroupContext();
            if (null === $aggregated->getSentAt()) {
                throw new RecoverableMessageHandlingException('Aggregated context not yet published to group');
            }

            $user = $delivery->getUser();
            if (!$user->hasBotChat()) {
                $delivery->markSkipped();
                $this->em->flush();

                return;
            }

            $text = $this->aggregationService->formatText($aggregated);
            $chatId = $user->getTelegramUserId();

            try {
                $this->telegramClient->sendMessageWithKeyboard(
                    $chatId,
                    $text, // развернутый текст
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
                $this->errorLogger->logTelegram(
                    403,
                    $user->getId(),
                    $user->getUsername(),
                    null,
                    null,
                    $e->getMessage(),
                );
                $user->markBotChatBlocked();
                $delivery->markSkipped();
                $this->em->flush();
                $this->logger->warning('PublishDmJobHandler: пользователь заблокировал бота', [
                    'dm_delivery_id' => $message->dmDeliveryId,
                    'telegram_user_id' => $chatId,
                ]);

                return;
            } catch (TelegramRateLimitException $e) {
                $this->errorLogger->logTelegram(
                    429,
                    $user->getId(),
                    $user->getUsername(),
                    null,
                    null,
                    $e->getMessage(),
                );
                throw $e;
            }

            $delivery->markSent();
            $this->em->flush();

            $this->logger->info('PublishDmJobHandler: контекст отправлен в ЛС', [
                'dm_delivery_id' => $message->dmDeliveryId,
                'aggregated_context_id' => $aggregated->getIdOrFail(),
                'telegram_user_id' => $chatId,
            ]);
        } finally {
            $lock->release();
        }
    }
}
