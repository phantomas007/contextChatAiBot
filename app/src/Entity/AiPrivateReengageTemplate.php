<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AiPrivateReengageTemplateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Тело напоминания о неактивности в ЛС с ИИ (HTML для Telegram).
 * Футер (например партнёрская ссылка) подставляется при отправке отдельно, в таблице только body.
 */
#[ORM\Entity(repositoryClass: AiPrivateReengageTemplateRepository::class)]
#[ORM\Table(name: 'ai_private_reengage_template')]
class AiPrivateReengageTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    public function __construct(string $body)
    {
        $this->body = $body;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }
}
