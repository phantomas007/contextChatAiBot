<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DailyStatsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DailyStatsRepository::class)]
#[ORM\Table(name: 'daily_stats')]
class DailyStats
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'date_immutable', unique: true)]
    private \DateTimeImmutable $statDate;

    #[ORM\Column(type: 'integer')]
    private int $messagesTotal = 0;

    #[ORM\Column(type: 'integer')]
    private int $activeGroups = 0;

    #[ORM\Column(type: 'integer')]
    private int $activeUsers = 0;

    #[ORM\Column(type: 'integer')]
    private int $newUsers = 0;

    #[ORM\Column(type: 'integer')]
    private int $totalSubs = 0;

    #[ORM\Column(type: 'integer')]
    private int $bricksGenerated = 0;

    #[ORM\Column(type: 'integer')]
    private int $aggregationsGenerated = 0;

    #[ORM\Column(type: 'integer')]
    private int $deliveriesGroupDaily = 0;

    #[ORM\Column(type: 'integer')]
    private int $deliveriesGroupCustom = 0;

    #[ORM\Column(type: 'integer')]
    private int $deliveriesGroupDispatchedDaily = 0;

    #[ORM\Column(type: 'integer')]
    private int $deliveriesGroupDispatchedCustom = 0;

    #[ORM\Column(type: 'integer')]
    private int $dmDeliveriesSent = 0;

    #[ORM\Column(type: 'integer')]
    private int $dmDeliveriesSkipped = 0;

    #[ORM\Column(type: 'integer')]
    private int $dmDeliveriesQueued = 0;

    #[ORM\Column(type: 'integer')]
    private int $errorsOllama = 0;

    #[ORM\Column(type: 'integer')]
    private int $errorsTelegram = 0;

    #[ORM\Column(type: 'integer')]
    private int $aiRequestsTotal = 0;

    #[ORM\Column(type: 'integer')]
    private int $aiRequestsUsers = 0;

    #[ORM\Column(type: 'integer')]
    private int $aiRequestsChats = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(\DateTimeImmutable $statDate)
    {
        $this->statDate = $statDate;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatDate(): \DateTimeImmutable
    {
        return $this->statDate;
    }

    public function getMessagesTotal(): int
    {
        return $this->messagesTotal;
    }

    public function setMessagesTotal(int $value): static
    {
        $this->messagesTotal = $value;

        return $this;
    }

    public function getActiveGroups(): int
    {
        return $this->activeGroups;
    }

    public function setActiveGroups(int $value): static
    {
        $this->activeGroups = $value;

        return $this;
    }

    public function getActiveUsers(): int
    {
        return $this->activeUsers;
    }

    public function setActiveUsers(int $value): static
    {
        $this->activeUsers = $value;

        return $this;
    }

    public function getNewUsers(): int
    {
        return $this->newUsers;
    }

    public function setNewUsers(int $value): static
    {
        $this->newUsers = $value;

        return $this;
    }

    public function getTotalSubs(): int
    {
        return $this->totalSubs;
    }

    public function setTotalSubs(int $value): static
    {
        $this->totalSubs = $value;

        return $this;
    }

    public function getBricksGenerated(): int
    {
        return $this->bricksGenerated;
    }

    public function setBricksGenerated(int $value): static
    {
        $this->bricksGenerated = $value;

        return $this;
    }

    public function getAggregationsGenerated(): int
    {
        return $this->aggregationsGenerated;
    }

    public function setAggregationsGenerated(int $value): static
    {
        $this->aggregationsGenerated = $value;

        return $this;
    }

    public function getDeliveriesGroupDaily(): int
    {
        return $this->deliveriesGroupDaily;
    }

    public function setDeliveriesGroupDaily(int $value): static
    {
        $this->deliveriesGroupDaily = $value;

        return $this;
    }

    public function getDeliveriesGroupCustom(): int
    {
        return $this->deliveriesGroupCustom;
    }

    public function setDeliveriesGroupCustom(int $value): static
    {
        $this->deliveriesGroupCustom = $value;

        return $this;
    }

    public function getDeliveriesGroupDispatchedDaily(): int
    {
        return $this->deliveriesGroupDispatchedDaily;
    }

    public function setDeliveriesGroupDispatchedDaily(int $value): static
    {
        $this->deliveriesGroupDispatchedDaily = $value;

        return $this;
    }

    public function getDeliveriesGroupDispatchedCustom(): int
    {
        return $this->deliveriesGroupDispatchedCustom;
    }

    public function setDeliveriesGroupDispatchedCustom(int $value): static
    {
        $this->deliveriesGroupDispatchedCustom = $value;

        return $this;
    }

    public function getDmDeliveriesSent(): int
    {
        return $this->dmDeliveriesSent;
    }

    public function setDmDeliveriesSent(int $value): static
    {
        $this->dmDeliveriesSent = $value;

        return $this;
    }

    public function getDmDeliveriesSkipped(): int
    {
        return $this->dmDeliveriesSkipped;
    }

    public function setDmDeliveriesSkipped(int $value): static
    {
        $this->dmDeliveriesSkipped = $value;

        return $this;
    }

    public function getDmDeliveriesQueued(): int
    {
        return $this->dmDeliveriesQueued;
    }

    public function setDmDeliveriesQueued(int $value): static
    {
        $this->dmDeliveriesQueued = $value;

        return $this;
    }

    public function getErrorsOllama(): int
    {
        return $this->errorsOllama;
    }

    public function setErrorsOllama(int $value): static
    {
        $this->errorsOllama = $value;

        return $this;
    }

    public function getErrorsTelegram(): int
    {
        return $this->errorsTelegram;
    }

    public function setErrorsTelegram(int $value): static
    {
        $this->errorsTelegram = $value;

        return $this;
    }

    public function getAiRequestsTotal(): int
    {
        return $this->aiRequestsTotal;
    }

    public function setAiRequestsTotal(int $value): static
    {
        $this->aiRequestsTotal = $value;

        return $this;
    }

    public function getAiRequestsUsers(): int
    {
        return $this->aiRequestsUsers;
    }

    public function setAiRequestsUsers(int $value): static
    {
        $this->aiRequestsUsers = $value;

        return $this;
    }

    public function getAiRequestsChats(): int
    {
        return $this->aiRequestsChats;
    }

    public function setAiRequestsChats(int $value): static
    {
        $this->aiRequestsChats = $value;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
