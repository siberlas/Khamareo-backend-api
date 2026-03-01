<?php

namespace App\Shared\Entity;

use App\Shared\Repository\AppSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Paramètres globaux de l'application (clé-valeur).
 * Utilisé notamment pour le mode "coming soon".
 */
#[ORM\Entity(repositoryClass: AppSettingsRepository::class)]
#[ORM\Table(name: 'app_settings')]
#[ORM\UniqueConstraint(name: 'uniq_app_settings_key', columns: ['setting_key'])]
class AppSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $settingKey;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $settingValue = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $settingKey, ?string $settingValue = null)
    {
        $this->settingKey   = $settingKey;
        $this->settingValue = $settingValue;
        $this->updatedAt    = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getSettingKey(): string { return $this->settingKey; }

    public function getSettingValue(): ?string { return $this->settingValue; }
    public function setSettingValue(?string $settingValue): self
    {
        $this->settingValue = $settingValue;
        $this->updatedAt    = new \DateTimeImmutable();
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
