<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MessageForAiRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MessageForAiRepository::class)]
#[ORM\Table(name: 'message_for_ai')]
class MessageForAi
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::BIGINT)]
    private int $telegramUserId;

    #[ORM\Column(type: Types::BIGINT)]
    private int $chatId;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $messageThreadId = null;

    #[ORM\Column(type: Types::BIGINT)]
    private int $replyToMessageId;

    #[ORM\Column(type: Types::TEXT)]
    private string $promptText;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $responseText = null;

    /** Краткое резюме (TL;DR) из форматтера. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $responseTldr = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    public function __construct(
        int $telegramUserId,
        int $chatId,
        int $replyToMessageId,
        string $promptText,
        ?int $messageThreadId = null,
    ) {
        $this->telegramUserId = $telegramUserId;
        $this->chatId = $chatId;
        $this->replyToMessageId = $replyToMessageId;
        $this->promptText = $promptText;
        $this->messageThreadId = $messageThreadId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTelegramUserId(): int
    {
        return $this->telegramUserId;
    }

    public function getChatId(): int
    {
        return $this->chatId;
    }

    public function getMessageThreadId(): ?int
    {
        return $this->messageThreadId;
    }

    public function getReplyToMessageId(): int
    {
        return $this->replyToMessageId;
    }

    public function getPromptText(): string
    {
        return $this->promptText;
    }

    public function getResponseText(): ?string
    {
        return $this->responseText;
    }

    public function setResponseText(?string $responseText): void
    {
        $this->responseText = $responseText;
    }

    public function getResponseTldr(): ?string
    {
        return $this->responseTldr;
    }

    public function setResponseTldr(?string $responseTldr): void
    {
        $this->responseTldr = $responseTldr;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): void
    {
        $this->errorMessage = $errorMessage;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): void
    {
        $this->sentAt = $sentAt;
    }

    public function isAwaitingDeepSeek(): bool
    {
        return null === $this->responseText && null === $this->errorMessage;
    }

    public function isReadyToSend(): bool
    {
        if (null !== $this->sentAt) {
            return false;
        }

        return null !== $this->responseText || null !== $this->errorMessage;
    }
}
