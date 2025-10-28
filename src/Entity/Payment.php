<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Serializer\Annotation\Groups;
use App\Enum\PaymentStatus;
use App\Repository\PaymentRepository;
use Symfony\Component\Validator\Constraints as Assert;
use ApiPlatform\Metadata\Post;
use App\Controller\PaymentStatusController;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ApiResource(
    normalizationContext: ['groups' => ['payment:read']],
    denormalizationContext: ['groups' => ['payment:write']],
    operations: [
        new Post(
            uriTemplate: '/payments/{id}/confirm',
            controller: PaymentStatusController::class,
            read: false,
            output: Payment::class,
            name: 'payment_confirm',
            security: "is_granted('ROLE_USER')"
        ),
    ]
)]
class Payment
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['payment:read', 'order:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'string', enumType: PaymentStatus::class)]
    #[Assert\NotBlank]
    #[Groups(['payment:read', 'payment:write', 'order:read'])]
    private PaymentStatus $status = PaymentStatus::PENDING;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Groups(['payment:read', 'payment:write', 'order:read'])]
    private string $provider; // Ex: 'stripe', 'paypal', 'manual'

    #[ORM\Column(type: 'float')]
    #[Assert\Positive]
    #[Groups(['payment:read', 'payment:write', 'order:read'])]
    private float $amount;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['payment:read', 'order:read'])]
    private ?string $transactionId = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['payment:read'])]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\OneToOne(inversedBy: 'payment', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['payment:write'])]
    private ?Order $order = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    public function setStatus(PaymentStatus $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): static
    {
        $this->provider = $provider;
        return $this;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function setAmount(float $amount): static
    {
        $this->amount = $amount;
        return $this;
    }

    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    public function setTransactionId(?string $transactionId): static
    {
        $this->transactionId = $transactionId;
        return $this;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeImmutable $paidAt): static
    {
        $this->paidAt = $paidAt;
        return $this;
    }

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function setOrder(?Order $order): static
    {
        $this->order = $order;
        return $this;
    }
}
