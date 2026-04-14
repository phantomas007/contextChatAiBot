<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserGroupSubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserGroupSubscriptionRepository::class)]
#[ORM\Table(name: 'user_group_subscriptions')]
#[ORM\UniqueConstraint(name: 'user_group_subscription_unique', columns: ['user_id', 'group_id'])]
class UserGroupSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: TelegramGroup::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TelegramGroup $group;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $countThreshold = null;

    /** 1440 = daily; используется для суточных подписок */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $timeInterval = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastDeliveredAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, TelegramGroup $group)
    {
        $this->user = $user;
        $this->group = $group;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getGroup(): TelegramGroup
    {
        return $this->group;
    }

    public function getCountThreshold(): ?int
    {
        return $this->countThreshold;
    }

    public function setCountThreshold(?int $threshold): void
    {
        $this->countThreshold = $threshold;
    }

    public function isDaily(): bool
    {
        return 1440 === $this->timeInterval;
    }

    public function setDaily(bool $enabled): void
    {
        $this->timeInterval = $enabled ? 1440 : null;
    }

    public function isDeliveryConfigured(): bool
    {
        return null !== $this->countThreshold || null !== $this->timeInterval;
    }

    public function getLastDeliveredAt(): ?\DateTimeImmutable
    {
        return $this->lastDeliveredAt;
    }

    public function setLastDeliveredAt(\DateTimeImmutable $dt): void
    {
        $this->lastDeliveredAt = $dt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
