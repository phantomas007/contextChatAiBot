<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GroupSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GroupSettingsRepository::class)]
#[ORM\Table(name: 'group_settings')]
class GroupSettings
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: TelegramGroup::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TelegramGroup $group;

    /** @var int<1,max>|null */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $countThreshold = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $dailyEnabled = true;

    public function __construct(TelegramGroup $group)
    {
        $this->group = $group;
    }

    public function getGroup(): TelegramGroup
    {
        return $this->group;
    }

    public function getCountThreshold(): ?int
    {
        return $this->countThreshold;
    }

    public function setCountThreshold(?int $countThreshold): void
    {
        $this->countThreshold = $countThreshold;
    }

    public function disableCustom(): void
    {
        $this->countThreshold = null;
    }

    public function isDailyEnabled(): bool
    {
        return $this->dailyEnabled;
    }

    public function setDailyEnabled(bool $enabled): void
    {
        $this->dailyEnabled = $enabled;
    }
}
