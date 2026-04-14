<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AggregatedGroupContext;
use App\Entity\Context;
use App\Entity\TelegramGroup;
use App\Entity\UserGroupSubscription;
use App\Enum\ContextType;
use App\Repository\AggregatedContextGroupRepository;
use App\Repository\ContextRepository;
use Doctrine\ORM\EntityManagerInterface;

class AggregationService
{
    public function __construct(
        private readonly ContextRepository $contextRepository,
        private readonly AggregatedContextGroupRepository $aggregatedContextGroupRepository,
        private readonly OllamaClient $ollamaClient,
        private readonly DeliveryErrorLogger $errorLogger,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return Context[]
     */
    public function getNewBricksForGroup(TelegramGroup $group, ContextType $type): array
    {
        $last = $this->aggregatedContextGroupRepository->findLastByType($group, $type);

        if (null !== $last) {
            return $this->contextRepository->findAfter($group, $last->getPeriodTo());
        }

        if (ContextType::DAILY !== $type) {
            $lastAny = $this->aggregatedContextGroupRepository->findLastCountBased($group);
            if (null !== $lastAny) {
                return $this->contextRepository->findAfter($group, $lastAny->getPeriodTo());
            }
        }

        return $this->contextRepository->findAfter($group, new \DateTimeImmutable('@0'));
    }

    /**
     * @return Context[]
     */
    public function getNewBricksForUser(UserGroupSubscription $sub): array
    {
        $after = $sub->getLastDeliveredAt() ?? new \DateTimeImmutable('today midnight');

        return $this->contextRepository->findAfter($sub->getGroup(), $after);
    }

    /**
     * @param Context[] $bricks
     */
    public function buildAndPersist(
        TelegramGroup $group,
        array $bricks,
        ContextType $type,
    ): AggregatedGroupContext {
        $executionTimeDisplay = null;

        if (1 === \count($bricks)) {
            $summary = $this->ollamaClient->stripSummaryWrapper($bricks[0]->getSummary());
        } else {
            $cleanSummaries = array_map(
                fn (Context $b): string => $this->ollamaClient->stripSummaryWrapper($b->getSummary()),
                $bricks,
            );

            $title = $group->getTitle() ?? '';

            try {
                $ollamaStart = hrtime(true);

                $summary = ContextType::DAILY === $type
                    ? $this->ollamaClient->summarizeDaily($cleanSummaries, $title)
                    : $this->ollamaClient->summarizeCountBased(
                        $cleanSummaries,
                        $title,
                        $type->getThresholdCount(),
                    );

                $seconds = (hrtime(true) - $ollamaStart) / 1_000_000_000;
                $executionTimeDisplay = \sprintf('%.2f с', $seconds);
            } catch (\Throwable $e) {
                $this->errorLogger->logOllama(
                    $group->getId() ?? 0,
                    $title,
                    $e->getMessage(),
                    str_contains(strtolower($e->getMessage()), 'timeout'),
                );
                throw $e;
            }
        }

        $messagesCount = array_sum(array_map(
            static fn (Context $b): int => $b->getMessagesCount(),
            $bricks,
        ));

        $aggregated = new AggregatedGroupContext(
            group: $group,
            summary: $summary,
            bricksCount: \count($bricks),
            messagesCount: $messagesCount,
            periodFrom: $bricks[0]->getPeriodFrom(),
            periodTo: end($bricks)->getPeriodTo(),
            settingsType: $type,
            executionTimeDisplay: $executionTimeDisplay,
        );

        $this->em->persist($aggregated);
        $this->em->flush();

        return $aggregated;
    }

    public function formatText(AggregatedGroupContext $ctx): string
    {
        $group = $ctx->getGroup();
        $title = htmlspecialchars($group->getTitle() ?? 'Группа', \ENT_QUOTES | \ENT_XML1);

        $from = $ctx->getPeriodFrom();
        $to = $ctx->getPeriodTo();
        $n = $ctx->getMessagesCount();

        $sep = str_repeat('━', 10);
        $periodLine = self::formatPeriodLine($from, $to);

        $metaLine = ContextType::DAILY === $ctx->getSettingsType()
            ? '🔥 Context · AI-саммари сообщений за день'
            : '🔥 Context · AI-саммари '.$n.' '.self::messagesWordRu($n);

        $header =
            "<b>{$title}</b>\n".
            $sep."\n".
            $metaLine."\n".
            $periodLine."\n".
            $sep;

        $body = trim($this->ollamaClient->stripDigestPublicationFooter($ctx->getSummary()));

        return $header."\n\n"
            .htmlspecialchars($body, \ENT_QUOTES | \ENT_XML1)
            .$this->ollamaClient->digestPublicationFooter();
    }

    private static function formatPeriodLine(\DateTimeInterface $from, \DateTimeInterface $to): string
    {
        if ($from->format('Y-m-d') === $to->format('Y-m-d')) {
            return $from->format('d.m.Y H:i').' — '.$to->format('H:i');
        }

        return $from->format('d.m.Y H:i').' — '.$to->format('d.m.Y H:i');
    }

    private static function messagesWordRu(int $n): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return 'сообщений';
        }

        return match ($mod10) {
            1 => 'сообщение',
            2, 3, 4 => 'сообщения',
            default => 'сообщений',
        };
    }
}
