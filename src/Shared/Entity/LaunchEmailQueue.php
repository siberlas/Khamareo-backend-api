<?php

namespace App\Shared\Entity;

use App\Shared\Repository\LaunchEmailQueueRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: LaunchEmailQueueRepository::class)]
#[ORM\Table(name: 'launch_email_queue')]
#[ORM\Index(columns: ['status'], name: 'idx_launch_queue_status')]
#[ORM\Index(columns: ['status', 'created_at'], name: 'idx_launch_queue_status_created')]
class LaunchEmailQueue
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT    = 'sent';
    public const STATUS_FAILED  = 'failed';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 30)]
    private string $promoCode;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $launchDate;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isNewsletter = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    public function __construct()
    {
        $this->id        = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): self { $this->email = $email; return $this; }

    public function getPromoCode(): string { return $this->promoCode; }
    public function setPromoCode(string $promoCode): self { $this->promoCode = $promoCode; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getLaunchDate(): \DateTimeImmutable { return $this->launchDate; }
    public function setLaunchDate(\DateTimeImmutable $launchDate): self { $this->launchDate = $launchDate; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function setSentAt(?\DateTimeImmutable $sentAt): self { $this->sentAt = $sentAt; return $this; }

    public function isNewsletter(): bool { return $this->isNewsletter; }
    public function setIsNewsletter(bool $isNewsletter): self { $this->isNewsletter = $isNewsletter; return $this; }

    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $errorMessage): self { $this->errorMessage = $errorMessage; return $this; }

    public function markAsSent(): self
    {
        $this->status = self::STATUS_SENT;
        $this->sentAt = new \DateTimeImmutable();
        $this->errorMessage = null;
        return $this;
    }

    public function markAsFailed(string $error): self
    {
        $this->status = self::STATUS_FAILED;
        $this->errorMessage = $error;
        return $this;
    }
}
