<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AggregatedContextDmDeliveryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AggregatedContextDmDeliveryRepository::class)]
#[ORM\Table(name: 'aggregated_context_dm_deliveries')]
#[ORM\UniqueConstraint(name: 'uq_agg_dm_delivery_agg_user', columns: ['aggregated_group_context_id', 'user_id'])]
class AggregatedContextDmDelivery
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AggregatedGroupContext::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AggregatedGroupContext $aggregatedGroupContext;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Момент создания строки фазы 1 («к отправке»). */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $queuedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $skippedAt = null;

    public function __construct(AggregatedGroupContext $aggregatedGroupContext, User $user)
    {
        $this->aggregatedGroupContext = $aggregatedGroupContext;
        $this->user = $user;
        $this->queuedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdOrFail(): int
    {
        return $this->id ?? throw new \LogicException('AggregatedContextDmDelivery ID is not set');
    }

    public function getAggregatedGroupContext(): AggregatedGroupContext
    {
        return $this->aggregatedGroupContext;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getQueuedAt(): \DateTimeImmutable
    {
        return $this->queuedAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getSkippedAt(): ?\DateTimeImmutable
    {
        return $this->skippedAt;
    }

    public function isPending(): bool
    {
        return null === $this->sentAt && null === $this->skippedAt;
    }

    public function markSent(): void
    {
        $this->sentAt = new \DateTimeImmutable();
    }

    public function markSkipped(): void
    {
        $this->skippedAt = new \DateTimeImmutable();
    }
}
