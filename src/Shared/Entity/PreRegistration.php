<?php

namespace App\Shared\Entity;

use App\Shared\Repository\PreRegistrationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Pré-inscription newsletter / liste d'attente (mode Coming Soon).
 */
#[ORM\Entity(repositoryClass: PreRegistrationRepository::class)]
#[ORM\Table(name: 'pre_registration')]
#[ORM\UniqueConstraint(name: 'uniq_pre_registration_email', columns: ['email'])]
#[ORM\Index(columns: ['created_at'], name: 'idx_pre_registration_created_at')]
class PreRegistration
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column]
    private bool $consentGiven = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id        = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): self { $this->email = $email; return $this; }

    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function setIpAddress(?string $ipAddress): self { $this->ipAddress = $ipAddress; return $this; }

    public function isConsentGiven(): bool { return $this->consentGiven; }
    public function setConsentGiven(bool $consentGiven): self { $this->consentGiven = $consentGiven; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
