<?php

namespace App\Shared\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use App\Shared\Repository\ConsentLogRepository;

/**
 * Journalisation des consentements (cookies, marketing opt-in, CGV).
 * Permet de prouver le consentement libre, éclairé et explicite (RGPD art. 7, CNIL).
 */
#[ORM\Entity(repositoryClass: ConsentLogRepository::class)]
#[ORM\Table(name: 'consent_log')]
#[ORM\Index(columns: ['user_id'], name: 'idx_consent_log_user')]
#[ORM\Index(columns: ['guest_token'], name: 'idx_consent_log_guest')]
#[ORM\Index(columns: ['type', 'created_at'], name: 'idx_consent_log_type_date')]
class ConsentLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /** Identifiant de l'utilisateur connecté (nullable si invité) */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $userId = null;

    /** Token invité (nullable si utilisateur connecté) */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $guestToken = null;

    /**
     * Type de consentement :
     * - 'cookies'         : bannière cookies (analytics, tiers)
     * - 'marketing_opt_in': opt-in email marketing au checkout
     * - 'cgv'             : acceptation des CGV lors d'une commande
     */
    #[ORM\Column(length: 30)]
    private string $type;

    /** Version du document consenti (ex : '1.0', '2026-02') */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $version = null;

    /**
     * Choix de l'utilisateur :
     * - 'accepted' / 'rejected' (cookies)
     * - 'granted' / 'denied'   (marketing opt-in)
     */
    #[ORM\Column(length: 20)]
    private string $choice;

    /** Adresse IP (pseudonymisée si nécessaire) */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    /** User-agent du navigateur */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }

    public function getUserId(): ?Uuid { return $this->userId; }
    public function setUserId(?Uuid $userId): self { $this->userId = $userId; return $this; }

    public function getGuestToken(): ?string { return $this->guestToken; }
    public function setGuestToken(?string $guestToken): self { $this->guestToken = $guestToken; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }

    public function getVersion(): ?string { return $this->version; }
    public function setVersion(?string $version): self { $this->version = $version; return $this; }

    public function getChoice(): string { return $this->choice; }
    public function setChoice(string $choice): self { $this->choice = $choice; return $this; }

    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function setIpAddress(?string $ipAddress): self { $this->ipAddress = $ipAddress; return $this; }

    public function getUserAgent(): ?string { return $this->userAgent; }
    public function setUserAgent(?string $userAgent): self { $this->userAgent = $userAgent; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
