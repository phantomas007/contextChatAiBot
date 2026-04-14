<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ContextType;
use App\Repository\AggregatedContextGroupRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AggregatedContextGroupRepository::class)]
#[ORM\Table(name: 'aggregated_contexts_for_group')]
#[ORM\Index(name: 'idx_aggregated_contexts_for_group_type_created', columns: ['group_id', 'settings_type', 'created_at'])]
#[ORM\Index(name: 'idx_aggregated_contexts_group_type_period', columns: ['group_id', 'settings_type', 'period_to'])]
#[ORM\Index(name: 'idx_aggregated_contexts_unsent', columns: ['sent_at', 'dispatched_at'])]
class AggregatedGroupContext
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TelegramGroup::class)]
    #[ORM\JoinColumn(nullable: false)]
    private TelegramGroup $group;

    #[ORM\Column(type: 'text')]
    private string $summary;

    #[ORM\Column(type: 'integer')]
    private int $bricksCount;

    #[ORM\Column(type: 'integer')]
    private int $messagesCount;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $periodFrom;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $periodTo;

    #[ORM\Column(length: 20, enumType: ContextType::class)]
    private ContextType $settingsType;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dispatchedAt = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $executionTimeDisplay = null;

    public function __construct(
        TelegramGroup $group,
        string $summary,
        int $bricksCount,
        int $messagesCount,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
        ContextType $settingsType,
        ?string $executionTimeDisplay = null,
    ) {
        $this->group = $group;
        $this->summary = $summary;
        $this->bricksCount = $bricksCount;
        $this->messagesCount = $messagesCount;
        $this->periodFrom = $periodFrom;
        $this->periodTo = $periodTo;
        $this->settingsType = $settingsType;
        $this->executionTimeDisplay = $executionTimeDisplay;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdOrFail(): int
    {
        return $this->id ?? throw new \LogicException('AggregatedGroupContext ID is not set (entity not persisted yet)');
    }

    public function getGroup(): TelegramGroup
    {
        return $this->group;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function getBricksCount(): int
    {
        return $this->bricksCount;
    }

    public function getMessagesCount(): int
    {
        return $this->messagesCount;
    }

    public function getPeriodFrom(): \DateTimeImmutable
    {
        return $this->periodFrom;
    }

    public function getPeriodTo(): \DateTimeImmutable
    {
        return $this->periodTo;
    }

    public function getSettingsType(): ContextType
    {
        return $this->settingsType;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function markAsSent(): void
    {
        $this->sentAt = new \DateTimeImmutable();
    }

    public function getDispatchedAt(): ?\DateTimeImmutable
    {
        return $this->dispatchedAt;
    }

    public function markAsDispatched(): void
    {
        $this->dispatchedAt = new \DateTimeImmutable();
    }

    public function getExecutionTimeDisplay(): ?string
    {
        return $this->executionTimeDisplay;
    }
}
