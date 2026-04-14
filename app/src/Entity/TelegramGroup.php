<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TelegramGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TelegramGroupRepository::class)]
#[ORM\Table(name: 'telegram_groups')]
class TelegramGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'bigint', unique: true)]
    private int $telegramChatId;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $title;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $botJoinedAt;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    /** @var Collection<int, UserGroup> */
    #[ORM\OneToMany(targetEntity: UserGroup::class, mappedBy: 'group', cascade: ['persist', 'remove'])]
    private Collection $userGroups;

    /** @var Collection<int, Message> */
    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'group')]
    private Collection $messages;

    public function __construct(
        int $telegramChatId,
        ?string $title = null,
        ?\DateTimeImmutable $botJoinedAt = null,
    ) {
        $this->telegramChatId = $telegramChatId;
        $this->title = $title;
        $this->botJoinedAt = $botJoinedAt ?? new \DateTimeImmutable();
        $this->userGroups = new ArrayCollection();
        $this->messages = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTelegramChatId(): int
    {
        return $this->telegramChatId;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function updateTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getBotJoinedAt(): \DateTimeImmutable
    {
        return $this->botJoinedAt;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function activate(): void
    {
        $this->isActive = true;
        $this->botJoinedAt = new \DateTimeImmutable();
    }

    public function deactivate(): void
    {
        $this->isActive = false;
    }

    /** @return Collection<int, UserGroup> */
    public function getUserGroups(): Collection
    {
        return $this->userGroups;
    }

    /** @return Collection<int, Message> */
    public function getMessages(): Collection
    {
        return $this->messages;
    }
}
