<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'bigint', unique: true)]
    private int $telegramUserId;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $username;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $firstName;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $registeredAt;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $hasBotChat = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastAiReengageSentAt = null;

    /** @var Collection<int, UserGroup> */
    #[ORM\OneToMany(targetEntity: UserGroup::class, mappedBy: 'user', cascade: ['persist', 'remove'])]
    private Collection $userGroups;

    public function __construct(
        int $telegramUserId,
        ?string $username = null,
        ?string $firstName = null,
    ) {
        $this->telegramUserId = $telegramUserId;
        $this->username = $username;
        $this->firstName = $firstName;
        $this->registeredAt = new \DateTimeImmutable();
        $this->userGroups = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTelegramUserId(): int
    {
        return $this->telegramUserId;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function getRegisteredAt(): \DateTimeImmutable
    {
        return $this->registeredAt;
    }

    public function hasBotChat(): bool
    {
        return $this->hasBotChat;
    }

    public function markBotChatStarted(): void
    {
        $this->hasBotChat = true;
    }

    public function markBotChatBlocked(): void
    {
        $this->hasBotChat = false;
    }

    public function getLastAiReengageSentAt(): ?\DateTimeImmutable
    {
        return $this->lastAiReengageSentAt;
    }

    public function markAiReengageSent(\DateTimeImmutable $at): void
    {
        $this->lastAiReengageSentAt = $at;
    }

    /** @return Collection<int, UserGroup> */
    public function getUserGroups(): Collection
    {
        return $this->userGroups;
    }
}
