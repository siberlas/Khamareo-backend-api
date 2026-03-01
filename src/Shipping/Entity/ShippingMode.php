<?php

namespace App\Shipping\Entity;

use App\Shipping\Repository\ShippingModeRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Delete;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: ShippingModeRepository::class)]
#[ApiResource(
    normalizationContext: ['groups' => ['shippingMode:read']],
    denormalizationContext: ['groups' => ['shippingMode:write']],
    operations: [
        new GetCollection(security: "is_granted('PUBLIC_ACCESS')"),
        new Get(security: "is_granted('PUBLIC_ACCESS')"),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Patch(security: "is_granted('ROLE_ADMIN')"),
        new Delete(security: "is_granted('ROLE_ADMIN')")
    ]
)]
class ShippingMode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['shippingMode:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Groups(['shippingMode:read', 'shippingMode:write', 'order:read', 'admin:order:list','admin:order:detail'])]
    private ?string $name = null; // "Domicile", "Point Relais", "Locker", "Express"

    #[ORM\Column(length: 50, unique: true)]
    #[Groups(['shippingMode:read', 'shippingMode:write', 'admin:order:list','admin:order:detail'])]
    private ?string $code = null; // "home", "relay_point", "locker", "express"

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['shippingMode:read', 'shippingMode:write', 'admin:order:list'])]
    private ?string $description = null;

    #[ORM\Column(type: 'boolean')]
    #[Groups(['shippingMode:read', 'shippingMode:write', 'admin:order:list'])]
    private bool $requiresPickupPoint = false; // true pour "relay_point" et "locker"

    #[ORM\Column(type: 'boolean')]
    #[Groups(['shippingMode:read', 'shippingMode:write', 'admin:order:list'])]
    private bool $isActive = true;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['shippingMode:read', 'shippingMode:write', 'admin:order:list'])]
    private ?string $icon = null; // "home", "store", "local_shipping", etc. (Material Icons)

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['shippingMode:read', 'admin:order:list'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\OneToMany(mappedBy: 'shippingMode', targetEntity: CarrierMode::class, cascade: ['persist', 'remove'])]
    private Collection $carrierModes;

    

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->carrierModes = new ArrayCollection();
    }

    // Getters/Setters
    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getCode(): ?string { return $this->code; }
    public function setCode(string $code): self { $this->code = $code; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }

    public function requiresPickupPoint(): bool { return $this->requiresPickupPoint; }
    public function setRequiresPickupPoint(bool $requiresPickupPoint): self 
    { 
        $this->requiresPickupPoint = $requiresPickupPoint; 
        return $this; 
    }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): self { $this->isActive = $isActive; return $this; }

    public function getIcon(): ?string { return $this->icon; }
    public function setIcon(?string $icon): self { $this->icon = $icon; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    /**
     * @return Collection<int, CarrierMode>
     */
    public function getCarrierModes(): Collection { return $this->carrierModes; }

    public function addCarrierMode(CarrierMode $carrierMode): self
    {
        if (!$this->carrierModes->contains($carrierMode)) {
            $this->carrierModes->add($carrierMode);
            $carrierMode->setShippingMode($this);
        }
        return $this;
    }

    public function removeCarrierMode(CarrierMode $carrierMode): self
    {
        if ($this->carrierModes->removeElement($carrierMode)) {
            if ($carrierMode->getShippingMode() === $this) {
                $carrierMode->setShippingMode(null);
            }
        }
        return $this;
    }
}