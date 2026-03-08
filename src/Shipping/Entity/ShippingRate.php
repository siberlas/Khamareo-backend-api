<?php

namespace App\Shipping\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Shipping\Repository\ShippingRateRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: ShippingRateRepository::class)]
#[ApiResource(
    normalizationContext: ['groups' => ['shipping_rate:read']],
    denormalizationContext: ['groups' => ['shipping_rate:write']]
)]
class ShippingRate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['shipping_rate:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CarrierMode::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['shipping_rate:read', 'shipping_rate:write'])]
    private ?CarrierMode $carrierMode = null;

    #[ORM\Column(length: 10)]
    #[Groups(['shipping_rate:read', 'shipping_rate:write'])]
    private ?string $zone = null;

    #[ORM\Column(type: 'integer')]
    #[Groups(['shipping_rate:read', 'shipping_rate:write'])]
    private ?int $minWeightGrams = null;

    #[ORM\Column(type: 'integer')]
    #[Groups(['shipping_rate:read', 'shipping_rate:write'])]
    private ?int $maxWeightGrams = null;

    #[ORM\Column(type: 'float')]
    #[Groups(['shipping_rate:read', 'shipping_rate:write'])]
    private ?float $price = null;

    #[ORM\Column(length: 2, nullable: true)]
    #[Groups(['shipping_rate:read', 'shipping_rate:write'])]
    private ?string $countryCode = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['shipping_rate:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // --- Getters & Setters ---
    public function getId(): ?int { return $this->id; }

    public function getCarrierMode(): ?CarrierMode { return $this->carrierMode; }
    public function setCarrierMode(?CarrierMode $carrierMode): self { $this->carrierMode = $carrierMode; return $this; }

    public function getZone(): ?string { return $this->zone; }
    public function setZone(string $zone): self { $this->zone = $zone; return $this; }

    public function getMinWeightGrams(): ?int { return $this->minWeightGrams; }
    public function setMinWeightGrams(int $minWeightGrams): self { $this->minWeightGrams = $minWeightGrams; return $this; }

    public function getMaxWeightGrams(): ?int { return $this->maxWeightGrams; }
    public function setMaxWeightGrams(int $maxWeightGrams): self { $this->maxWeightGrams = $maxWeightGrams; return $this; }

    public function getPrice(): ?float { return $this->price; }
    public function setPrice(float $price): self { $this->price = $price; return $this; }

    public function getCountryCode(): ?string { return $this->countryCode; }
    public function setCountryCode(?string $countryCode): self { $this->countryCode = $countryCode; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
