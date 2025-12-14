<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\ShippingRateRepository;
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

    #[ORM\Column(length: 100)]
    #[Groups(['shipping_rate:read', 'shipping_rate:write'])]
    private ?string $provider = null; // Colissimo, MondialRelay

    #[ORM\Column(length: 100)]
    #[Groups(['shipping_rate:read', 'shipping_rate:write'])]
    private ?string $zone = null; // ex: "France", "UE", "OM1", "OM2"

    #[ORM\Column(type: 'float')]
    #[Groups(['shipping_rate:read', 'shipping_rate:write'])]
    private ?float $minWeight = null; // kg

    #[ORM\Column(type: 'float')]
    #[Groups(['shipping_rate:read', 'shipping_rate:write'])]
    private ?float $maxWeight = null; // kg

    #[ORM\Column(type: 'float')]
    #[Groups(['shipping_rate:read', 'shipping_rate:write'])]
    private ?float $price = null; // €

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['shipping_rate:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // --- Getters & Setters ---
    public function getId(): ?int { return $this->id; }
    public function getProvider(): ?string { return $this->provider; }
    public function setProvider(string $provider): self { $this->provider = $provider; return $this; }
    public function getZone(): ?string { return $this->zone; }
    public function setZone(string $zone): self { $this->zone = $zone; return $this; }
    public function getMinWeight(): ?float { return $this->minWeight; }
    public function setMinWeight(float $minWeight): self { $this->minWeight = $minWeight; return $this; }
    public function getMaxWeight(): ?float { return $this->maxWeight; }
    public function setMaxWeight(float $maxWeight): self { $this->maxWeight = $maxWeight; return $this; }
    public function getPrice(): ?float { return $this->price; }
    public function setPrice(float $price): self { $this->price = $price; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
