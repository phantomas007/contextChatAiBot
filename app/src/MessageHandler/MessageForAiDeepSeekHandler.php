<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\MessageForAi;
use App\Message\AiReplySendMessage;
use App\Message\MessageForAiDeepSeekMessage;
use App\Repository\MessageForAiRepository;
use App\Service\AiRequestRateLimiter;
use App\Service\AiTelegramFormatter;
use App\Service\DeepSeekClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class MessageForAiDeepSeekHandler
{
    private const int LOCK_TTL = 180;

    public function __construct(
        private readonly MessageForAiRepository $messageForAiRepository,
        private readonly DeepSeekClient $deepSeekClient,
        private readonly EntityManagerInterface $em,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $bus,
        private readonly AiTelegramFormatter $aiTelegramFormatter,
        private readonly AiRequestRateLimiter $aiRequestRateLimiter,
    ) {
    }

    public function __invoke(MessageForAiDeepSeekMessage $message): void
    {
        $lock = $this->lockFactory->createLock(
            'ai_deepseek_'.$message->messageForAiId,
            ttl: self::LOCK_TTL,
            autoRelease: false,
        );

        if (!$lock->acquire()) {
            return;
        }

        try {
            $entity = $this->messageForAiRepository->find($message->messageForAiId);
            if (!$entity instanceof MessageForAi) {
                return;
            }

            if (!$entity->isAwaitingDeepSeek()) {
                return;
            }

            if (!$this->deepSeekClient->isConfigured()) {
                $entity->setErrorMessage('Сервис ИИ не настроен.');
                $this->em->flush();
                $this->dispatchSendIfPossible($entity->getId());

                return;
            }

            try {
                $text = $this->deepSeekClient->chatCompletion($entity->getPromptText());
                $entity->setErrorMessage(null);

                try {
                    $chatId = $entity->getChatId();
                    $isPrivate = $chatId > 0;
                    $isGroupChat = $chatId < 0;
                    if ($isPrivate) {
                        $used = $this->aiRequestRateLimiter->getPrivateDmUsageToday($entity->getTelegramUserId());
                        $maxPerDay = AiRequestRateLimiter::MAX_PER_DAY_PRIVATE_DM;
                    } else {
                        $used = $this->aiRequestRateLimiter->getGroupUsageToday($chatId);
                        $maxPerDay = AiRequestRateLimiter::MAX_PER_DAY_GROUP;
                    }
                    $formatted = $this->aiTelegramFormatter->format(
                        $text,
                        $used,
                        $maxPerDay,
                        $isPrivate,
                        $isGroupChat,
                    );
                    $entity->setResponseText($formatted['message']);
                    $entity->setResponseTldr($formatted['tldr']);
                } catch (\Throwable $formatEx) {
                    $this->logger->warning('AiTelegramFormatter failed, using raw DeepSeek text', [
                        'id' => $entity->getId(),
                        'error' => $formatEx->getMessage(),
                    ]);
                    $entity->setResponseText($text);
                    $entity->setResponseTldr(null);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('DeepSeek completion failed', [
                    'id' => $entity->getId(),
                    'error' => $e->getMessage(),
                ]);
                $entity->setErrorMessage($e->getMessage());
            }

            $this->em->flush();
            $this->dispatchSendIfPossible($entity->getId());  // отправляем в очередь сразу ?
        } finally {
            $lock->release();
        }
    }

    private function dispatchSendIfPossible(?int $id): void
    {
        if (null === $id) {
            return;
        }

        $this->bus->dispatch(new AiReplySendMessage($id));
    }
}
