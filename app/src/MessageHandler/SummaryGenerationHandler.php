<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Context;
use App\Message\SummaryJobMessage;
use App\Repository\MessageRepository;
use App\Repository\TelegramGroupRepository;
use App\Service\DeliveryErrorLogger;
use App\Service\OllamaClient;
use App\Summary\SummaryBrickSize;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Генерирует кирпичи (Context) через Ollama.
 *
 * Обрабатывает SummaryJobMessage из очереди summary_jobs.
 * Берёт несуммаризованные сообщения порциями по SummaryBrickSize::MESSAGES_PER_BRICK;
 * хвост меньше этого размера не суммаризируется (ждёт накопления полного кирпича).
 * Отправляет в Ollama для саммари,
 * создаёт Context (кирпич), помечает сообщения как суммаризованные.
 * Использует lock для защиты от параллельной обработки одной группы.
 * Проверка агрегации запускается отдельной cron-командой app:check-aggregation.
 */
#[AsMessageHandler]
final class SummaryGenerationHandler
{
    public function __construct(
        private readonly TelegramGroupRepository $groupRepository,
        private readonly MessageRepository $messageRepository,
        private readonly OllamaClient $ollamaClient,
        private readonly DeliveryErrorLogger $errorLogger,
        private readonly EntityManagerInterface $em,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'OLLAMA_MODEL')]
        private readonly string $ollamaModel,
    ) {
    }

    public function __invoke(SummaryJobMessage $message): void
    {
        $group = $this->groupRepository->find($message->groupId);
        if (null === $group) {
            return;
        }

        $lock = $this->lockFactory->createLock(
            'summary_group_'.$message->groupId,
            ttl: 300,
            autoRelease: false,
        );

        if (!$lock->acquire()) {
            return;
        }

        try {
            while (true) {
                $messages = $this->messageRepository->findUnsummarized($group, SummaryBrickSize::MESSAGES_PER_BRICK);
                if (empty($messages)) {
                    break;
                }

                if (\count($messages) < SummaryBrickSize::MESSAGES_PER_BRICK) {
                    break;
                }

                $periodFrom = $messages[0]->getCreatedAt();
                $periodTo = end($messages)->getCreatedAt();

                $formatted = array_map(
                    static fn ($m): array => [
                        'username' => $m->getUsername(),
                        'text' => $m->getText(),
                        'date' => $m->getCreatedAt()->format('d.m.Y H:i'),
                    ],
                    $messages,
                );

                try {
                    $ollamaStart = hrtime(true);
                    $summary = $this->ollamaClient->summarizeYa2(
                        $formatted,
                        $group->getTitle() ?? '',
                        \count($messages),
                    );
                    $seconds = (hrtime(true) - $ollamaStart) / 1_000_000_000;
                    $executionTimeDisplay = \sprintf('%.2f с', $seconds);
                } catch (\Throwable $e) {
                    $this->logger->error('SummaryGenerationHandler: Ollama error', [
                        'group_id' => $group->getId(),
                        'group_title' => $group->getTitle(),
                        'error' => $e->getMessage(),
                    ]);
                    $this->errorLogger->logOllama(
                        $group->getId() ?? 0,
                        $group->getTitle() ?? '',
                        $e->getMessage(),
                        str_contains(strtolower($e->getMessage()), 'timeout'),
                    );
                    throw $e;
                }

                $context = new Context(
                    group: $group,
                    summary: $summary,
                    messagesCount: \count($messages),
                    periodFrom: $periodFrom,
                    periodTo: $periodTo,
                    model: $this->ollamaModel,
                    executionTimeDisplay: $executionTimeDisplay,
                );

                $this->em->persist($context);

                foreach ($messages as $msg) {
                    $msg->markAsSummarized();
                }

                $this->em->flush();
            }
        } finally {
            $lock->release();
        }
    }
}
