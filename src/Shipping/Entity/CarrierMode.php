<?php

namespace App\Shipping\Entity;

use App\Shipping\Repository\CarrierModeRepository;
use Doctrine\ORM\Mapping as ORM;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Delete;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: CarrierModeRepository::class)]
#[ApiResource(
    normalizationContext: ['groups' => ['carrierMode:read']],
    denormalizationContext: ['groups' => ['carrierMode:write']],
    operations: [
        new GetCollection(security: "is_granted('PUBLIC_ACCESS')"),
        new Get(security: "is_granted('PUBLIC_ACCESS')"),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Patch(security: "is_granted('ROLE_ADMIN')"),
        new Delete(security: "is_granted('ROLE_ADMIN')")
    ]
)]
class CarrierMode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['carrierMode:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Carrier::class, inversedBy: 'carrierModes')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['carrierMode:read', 'carrierMode:write'])]
    private ?Carrier $carrier = null;

    #[ORM\ManyToOne(targetEntity: ShippingMode::class, inversedBy: 'carrierModes')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['carrierMode:read', 'carrierMode:write'])]
    private ?ShippingMode $shippingMode = null;

    /**
     * Zones géographiques supportées par cette combinaison
     * Ex: ["FR", "DOM"] ou ["FR", "EU"] ou ["FR", "DOM", "EU", "INTL"]
     */
    #[ORM\Column(type: 'json')]
    #[Groups(['carrierMode:read', 'carrierMode:write'])]
    private array $supportedZones = [];

    #[ORM\Column(type: 'boolean')]
    #[Groups(['carrierMode:read', 'carrierMode:write'])]
    private bool $isActive = true;

    /**
     * Délai de livraison estimé (en jours)
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['carrierMode:read', 'carrierMode:write'])]
    private ?int $estimatedDeliveryDays = null;

    /**
     * Délai de livraison min (en jours)
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['carrierMode:read', 'carrierMode:write'])]
    private ?int $deliveryMinDays = null;

    /**
     * Délai de livraison max (en jours)
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['carrierMode:read', 'carrierMode:write'])]
    private ?int $deliveryMaxDays = null;

    /**
     * Unité des délais (working_days | calendar_days)
     */
    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['carrierMode:read', 'carrierMode:write'])]
    private ?string $deliveryDaysUnit = null;

    /**
     * Note sur les délais (ex: variable en temps de crise)
     */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['carrierMode:read', 'carrierMode:write'])]
    private ?string $deliveryDaysNote = null;

    /**
     * Prix de base (peut être surchargé par ShippingRate selon poids/zone)
     */
    #[ORM\Column(type: 'float', nullable: true)]
    #[Groups(['carrierMode:read', 'carrierMode:write'])]
    private ?float $basePrice = null;

    /**
     * Clé du code produit Colissimo (ex: france_metro, outre_mer, outre_mer_eco, union_europeenne, europe_hors_ue, international)
     */
    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['carrierMode:read', 'carrierMode:write'])]
    private ?string $colissimoProductCodeKey = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['carrierMode:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // Getters/Setters
    public function getId(): ?int { return $this->id; }

    public function getCarrier(): ?Carrier { return $this->carrier; }
    public function setCarrier(?Carrier $carrier): self 
    { 
        $this->carrier = $carrier; 
        return $this; 
    }

    public function getShippingMode(): ?ShippingMode { return $this->shippingMode; }
    public function setShippingMode(?ShippingMode $shippingMode): self 
    { 
        $this->shippingMode = $shippingMode; 
        return $this; 
    }

    public function getSupportedZones(): array { return $this->supportedZones; }
    public function setSupportedZones(array $supportedZones): self 
    { 
        $this->supportedZones = $supportedZones; 
        return $this; 
    }

    /**
     * Vérifie si cette combinaison supporte une zone donnée
     */
    public function supportsZone(string $zone): bool
    {
        return in_array($zone, $this->supportedZones, true);
    }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): self 
    { 
        $this->isActive = $isActive; 
        return $this; 
    }

    public function getEstimatedDeliveryDays(): ?int { return $this->estimatedDeliveryDays; }
    public function setEstimatedDeliveryDays(?int $estimatedDeliveryDays): self 
    { 
        $this->estimatedDeliveryDays = $estimatedDeliveryDays; 
        return $this; 
    }

    public function getDeliveryMinDays(): ?int { return $this->deliveryMinDays; }
    public function setDeliveryMinDays(?int $deliveryMinDays): self
    {
        $this->deliveryMinDays = $deliveryMinDays;
        return $this;
    }

    public function getDeliveryMaxDays(): ?int { return $this->deliveryMaxDays; }
    public function setDeliveryMaxDays(?int $deliveryMaxDays): self
    {
        $this->deliveryMaxDays = $deliveryMaxDays;
        return $this;
    }

    public function getDeliveryDaysUnit(): ?string { return $this->deliveryDaysUnit; }
    public function setDeliveryDaysUnit(?string $deliveryDaysUnit): self
    {
        $this->deliveryDaysUnit = $deliveryDaysUnit;
        return $this;
    }

    public function getDeliveryDaysNote(): ?string { return $this->deliveryDaysNote; }
    public function setDeliveryDaysNote(?string $deliveryDaysNote): self
    {
        $this->deliveryDaysNote = $deliveryDaysNote;
        return $this;
    }

    public function getBasePrice(): ?float { return $this->basePrice; }
    public function setBasePrice(?float $basePrice): self 
    { 
        $this->basePrice = $basePrice; 
        return $this; 
    }

    public function getColissimoProductCodeKey(): ?string { return $this->colissimoProductCodeKey; }
    public function setColissimoProductCodeKey(?string $colissimoProductCodeKey): self
    {
        $this->colissimoProductCodeKey = $colissimoProductCodeKey;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    /**
     * Nom d'affichage complet pour le frontend
     * Ex: "Colissimo - Domicile" ou "Mondial Relay - Point Relais"
     */
    #[Groups(['carrierMode:read'])]
    public function getDisplayName(): string
    {
        return sprintf(
            '%s - %s',
            $this->carrier?->getName() ?? 'N/A',
            $this->shippingMode?->getName() ?? 'N/A'
        );
    }
}