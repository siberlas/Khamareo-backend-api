<?php

namespace App\Marketing\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use App\Order\Entity\Order;

#[ORM\Entity]
#[ORM\Table(name: 'promo_code_redemption')]
#[ApiResource(
    normalizationContext: ['groups' => ['promo:read']],
    operations: [
        new GetCollection(security: "is_granted('ROLE_ADMIN')"),
        new Get(security: "is_granted('ROLE_ADMIN')")
    ]
)]
class PromoCodeRedemption
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['promo:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: PromoCode::class, inversedBy: 'redemptions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['promo:read'])]
    private ?PromoCode $promoCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['promo:read'])]
    private ?string $email = null;

    #[ORM\Column(length: 20)]
    #[Groups(['promo:read'])]
    private string $customerType = 'guest';

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['promo:read'])]
    private ?Order $order = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['promo:read'])]
    private ?string $discountAmount = null;

    #[ORM\Column]
    #[Groups(['promo:read'])]
    private ?\DateTimeImmutable $usedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->usedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getPromoCode(): ?PromoCode
    {
        return $this->promoCode;
    }

    public function setPromoCode(?PromoCode $promoCode): static
    {
        $this->promoCode = $promoCode;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getCustomerType(): string
    {
        return $this->customerType;
    }

    public function setCustomerType(string $customerType): static
    {
        $this->customerType = $customerType;
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

    public function getDiscountAmount(): ?string
    {
        return $this->discountAmount;
    }

    public function setDiscountAmount(?string $discountAmount): static
    {
        $this->discountAmount = $discountAmount;
        return $this;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function setUsedAt(\DateTimeImmutable $usedAt): static
    {
        $this->usedAt = $usedAt;
        return $this;
    }
}
