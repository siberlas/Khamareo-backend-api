<?php

namespace App\Shipping\Entity;

use App\Shipping\Repository\CarrierRepository;
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

#[ORM\Entity(repositoryClass: CarrierRepository::class)]
#[ApiResource(
    normalizationContext: ['groups' => ['carrier:read']],
    denormalizationContext: ['groups' => ['carrier:write']],
    operations: [
        new GetCollection(security: "is_granted('PUBLIC_ACCESS')"),
        new Get(security: "is_granted('PUBLIC_ACCESS')"),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Patch(security: "is_granted('ROLE_ADMIN')"),
        new Delete(security: "is_granted('ROLE_ADMIN')")
    ]
)]
class Carrier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['carrier:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Groups(['carrier:read', 'carrier:write', 'order:read', 'admin:order:list','admin:order:detail'])]
    private ?string $name = null; // "Colissimo", "Mondial Relay", "Chronopost"

    #[ORM\Column(length: 50, unique: true)]
    #[Groups(['carrier:read', 'carrier:write', 'admin:order:list','admin:order:detail'])]
    private ?string $code = null; // "colissimo", "mondialrelay", "chronopost"

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['carrier:read', 'carrier:write'])]
    private ?string $description = null;

    #[ORM\Column(type: 'integer')]
    #[Groups(['carrier:read', 'carrier:write'])]
    private int $maxWeightGrams = 30000; // 30 kg par défaut

    #[ORM\Column(type: 'integer')]
    #[Groups(['carrier:read', 'carrier:write'])]
    private int $minWeightGrams = 1;

    #[ORM\Column(type: 'boolean')]
    #[Groups(['carrier:read', 'carrier:write'])]
    private bool $isActive = true;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['carrier:read', 'carrier:write'])]
    private ?string $logoUrl = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['carrier:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\OneToMany(mappedBy: 'carrier', targetEntity: CarrierMode::class, cascade: ['persist', 'remove'])]
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

    public function getMaxWeightGrams(): int { return $this->maxWeightGrams; }
    public function setMaxWeightGrams(int $maxWeightGrams): self { $this->maxWeightGrams = $maxWeightGrams; return $this; }

    public function getMinWeightGrams(): int { return $this->minWeightGrams; }
    public function setMinWeightGrams(int $minWeightGrams): self { $this->minWeightGrams = $minWeightGrams; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): self { $this->isActive = $isActive; return $this; }

    public function getLogoUrl(): ?string { return $this->logoUrl; }
    public function setLogoUrl(?string $logoUrl): self { $this->logoUrl = $logoUrl; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    /**
     * @return Collection<int, CarrierMode>
     */
    public function getCarrierModes(): Collection { return $this->carrierModes; }

    public function addCarrierMode(CarrierMode $carrierMode): self
    {
        if (!$this->carrierModes->contains($carrierMode)) {
            $this->carrierModes->add($carrierMode);
            $carrierMode->setCarrier($this);
        }
        return $this;
    }

    public function removeCarrierMode(CarrierMode $carrierMode): self
    {
        if ($this->carrierModes->removeElement($carrierMode)) {
            if ($carrierMode->getCarrier() === $this) {
                $carrierMode->setCarrier(null);
            }
        }
        return $this;
    }
}