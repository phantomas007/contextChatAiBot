<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MessageRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'messages')]
#[ORM\Index(name: 'idx_messages_group_summarized_created', columns: ['group_id', 'summarized_at', 'created_at'])]
#[ORM\UniqueConstraint(name: 'messages_unique', columns: ['telegram_message_id', 'group_id'])]
class Message
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'bigint')]
    private int $telegramMessageId;

    #[ORM\ManyToOne(targetEntity: TelegramGroup::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false)]
    private TelegramGroup $group;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $telegramUserId;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $username;

    #[ORM\Column(type: 'text')]
    private string $text;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $summarizedAt = null;

    public function __construct(
        int $telegramMessageId,
        TelegramGroup $group,
        ?int $telegramUserId,
        ?string $username,
        string $text,
        \DateTimeImmutable $createdAt,
    ) {
        $this->telegramMessageId = $telegramMessageId;
        $this->group = $group;
        $this->telegramUserId = $telegramUserId;
        $this->username = $username;
        $this->text = $text;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTelegramMessageId(): int
    {
        return $this->telegramMessageId;
    }

    public function getGroup(): TelegramGroup
    {
        return $this->group;
    }

    public function getTelegramUserId(): ?int
    {
        return $this->telegramUserId;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSummarizedAt(): ?\DateTimeImmutable
    {
        return $this->summarizedAt;
    }

    public function markAsSummarized(): void
    {
        $this->summarizedAt = new \DateTimeImmutable();
    }
}
