<?php

namespace App\Shipping\Entity;

use App\Shipping\Repository\CartonRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Format de carton disponible pour l'emballage des colis (page admin
 * "Emballage") — dimensions et poids à vide utilisés pour calculer le poids
 * volumétrique et le poids déclaré à l'étiquette.
 */
#[ORM\Entity(repositoryClass: CartonRepository::class)]
class Carton
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'integer')]
    private int $lengthCm;

    #[ORM\Column(type: 'integer')]
    private int $widthCm;

    #[ORM\Column(type: 'integer')]
    private int $heightCm;

    #[ORM\Column(type: 'integer')]
    private int $emptyWeightGrams;

    /**
     * Carton désactivé (rupture de stock d'emballage) : conservé en base et sur
     * les colis existants, mais exclu du colisage automatique de l'estimation
     * et du sélecteur de préparation.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getLengthCm(): int { return $this->lengthCm; }
    public function setLengthCm(int $lengthCm): self { $this->lengthCm = $lengthCm; return $this; }

    public function getWidthCm(): int { return $this->widthCm; }
    public function setWidthCm(int $widthCm): self { $this->widthCm = $widthCm; return $this; }

    public function getHeightCm(): int { return $this->heightCm; }
    public function setHeightCm(int $heightCm): self { $this->heightCm = $heightCm; return $this; }

    public function getEmptyWeightGrams(): int { return $this->emptyWeightGrams; }
    public function setEmptyWeightGrams(int $emptyWeightGrams): self { $this->emptyWeightGrams = $emptyWeightGrams; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): self { $this->isActive = $isActive; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** Volume brut en cm³ (L×l×h) — utilisé pour le remplissage/capacité. */
    public function getVolumeCm3(): int
    {
        return $this->lengthCm * $this->widthCm * $this->heightCm;
    }

    /** Poids volumétrique Colissimo : L×l×h (cm) / 5000 = poids en kg. */
    public function getVolumetricWeightGrams(): int
    {
        return (int) round($this->getVolumeCm3() / 5000 * 1000);
    }
}
