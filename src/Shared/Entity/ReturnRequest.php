<?php

namespace App\Shared\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use App\Shared\Repository\ReturnRequestRepository;

/**
 * Demande de rétractation – Art. L221-18 Code de la consommation.
 * Délai : 14 jours à compter de la réception de la commande.
 */
#[ORM\Entity(repositoryClass: ReturnRequestRepository::class)]
#[ORM\Table(name: 'return_request')]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['email'], name: 'idx_return_request_email')]
#[ORM\Index(columns: ['order_number'], name: 'idx_return_request_order')]
#[ORM\Index(columns: ['status'], name: 'idx_return_request_status')]
#[ApiResource(
    normalizationContext: ['groups' => ['return_request:read']],
    denormalizationContext: ['groups' => ['return_request:write']],
    operations: [
        new GetCollection(
            uriTemplate: '/admin/return-requests',
            security: "is_granted('ROLE_ADMIN')"
        ),
        new Get(
            uriTemplate: '/admin/return-requests/{id}',
            security: "is_granted('ROLE_ADMIN')"
        ),
        new Patch(
            uriTemplate: '/admin/return-requests/{id}',
            security: "is_granted('ROLE_ADMIN')"
        ),
    ]
)]
#[ApiFilter(SearchFilter::class, properties: ['status' => 'exact', 'email' => 'partial'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'status'])]
class ReturnRequest
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['return_request:read'])]
    private Uuid $id;

    #[ORM\Column(length: 100)]
    #[Groups(['return_request:read'])]
    private string $firstName;

    #[ORM\Column(length: 100)]
    #[Groups(['return_request:read'])]
    private string $lastName;

    #[ORM\Column(length: 255)]
    #[Groups(['return_request:read'])]
    private string $email;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['return_request:read'])]
    private ?string $orderNumber = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['return_request:read'])]
    private ?string $reason = null;

    /** 'pending' | 'accepted' | 'rejected' */
    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    #[Groups(['return_request:read', 'return_request:write'])]
    private string $status = 'pending';

    /** Note interne de l'admin (non visible par le client) */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['return_request:read', 'return_request:write'])]
    private ?string $adminNote = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['return_request:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['return_request:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->id        = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }

    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $v): self { $this->firstName = $v; return $this; }

    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $v): self { $this->lastName = $v; return $this; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $v): self { $this->email = $v; return $this; }

    public function getOrderNumber(): ?string { return $this->orderNumber; }
    public function setOrderNumber(?string $v): self { $this->orderNumber = $v; return $this; }

    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $v): self { $this->reason = $v; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): self { $this->status = $v; return $this; }

    public function getAdminNote(): ?string { return $this->adminNote; }
    public function setAdminNote(?string $v): self { $this->adminNote = $v; return $this; }

    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function setIpAddress(?string $v): self { $this->ipAddress = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
}
