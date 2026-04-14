<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContextRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContextRepository::class)]
#[ORM\Table(name: 'contexts')]
#[ORM\Index(name: 'idx_contexts_group_created', columns: ['group_id', 'created_at'])]
#[ORM\Index(name: 'idx_contexts_group_period_to', columns: ['group_id', 'period_to'])]
class Context
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
    private int $messagesCount;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $periodFrom;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $periodTo;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $executionTimeDisplay = null;

    public function __construct(
        TelegramGroup $group,
        string $summary,
        int $messagesCount,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
        ?string $model = null,
        ?string $executionTimeDisplay = null,
    ) {
        $this->group = $group;
        $this->summary = $summary;
        $this->messagesCount = $messagesCount;
        $this->periodFrom = $periodFrom;
        $this->periodTo = $periodTo;
        $this->model = $model;
        $this->executionTimeDisplay = $executionTimeDisplay;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGroup(): TelegramGroup
    {
        return $this->group;
    }

    public function getSummary(): string
    {
        return $this->summary;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getExecutionTimeDisplay(): ?string
    {
        return $this->executionTimeDisplay;
    }
}
