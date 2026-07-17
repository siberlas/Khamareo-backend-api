<?php

namespace App\Marketing\Entity;

use App\Marketing\Repository\EmailSendLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal des envois automatisés (segments newsletter/promo/panier), utilisé
 * pour la dédup cross-segment : un contact ne doit jamais recevoir 2 emails
 * automatisés le même jour pour des raisons différentes.
 */
#[ORM\Entity(repositoryClass: EmailSendLogRepository::class)]
#[ORM\Index(columns: ['email', 'sent_at'], name: 'idx_email_send_log_email_date')]
class EmailSendLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $email;

    #[ORM\Column(length: 30)]
    private string $segment;

    #[ORM\Column]
    private \DateTimeImmutable $sentAt;

    public function __construct(string $email, string $segment)
    {
        $this->email = $email;
        $this->segment = $segment;
        $this->sentAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function getSegment(): string { return $this->segment; }
    public function getSentAt(): \DateTimeImmutable { return $this->sentAt; }
}
