<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DeliveryErrorRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeliveryErrorRepository::class)]
#[ORM\Table(name: 'delivery_errors')]
#[ORM\Index(name: 'idx_delivery_errors_type_date', columns: ['error_type', 'occurred_at'])]
class DeliveryError
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    /** 'ollama_timeout' | 'ollama_error' | 'tg_403' | 'tg_429' | 'tg_other' */
    #[ORM\Column(length: 20)]
    private string $errorType;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $errorCode;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $context;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $errorType, ?int $errorCode, array $context)
    {
        $this->errorType = $errorType;
        $this->errorCode = $errorCode;
        $this->context = $context;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getErrorType(): string
    {
        return $this->errorType;
    }

    public function getErrorCode(): ?int
    {
        return $this->errorCode;
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
