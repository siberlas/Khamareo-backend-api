<?php

namespace App\Shipping\Entity;

use App\Shipping\Repository\ParcelItemRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Serializer\Annotation\Groups;
use ApiPlatform\Metadata\ApiResource;
use App\Order\Entity\OrderItem;

#[ORM\Entity(repositoryClass: ParcelItemRepository::class)]
#[ApiResource(
    normalizationContext: ['groups' => ['parcelItem:read']],
    denormalizationContext: ['groups' => ['parcelItem:write']],
    operations: []
)]
class ParcelItem
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['parcelItem:read', 'parcel:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Parcel::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Parcel $parcel = null;

    #[ORM\ManyToOne(targetEntity: OrderItem::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['parcelItem:read', 'parcel:read'])]
    private ?OrderItem $orderItem = null;

    /**
     * Quantité de CE produit dans CE colis
     * (peut être < à la quantité totale de l'OrderItem si réparti sur plusieurs colis)
     */
    #[ORM\Column(type: 'integer')]
    #[Groups(['parcelItem:read', 'parcel:read'])]
    private int $quantity = 1;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    // Getters/Setters
    public function getId(): ?Uuid { return $this->id; }

    public function getParcel(): ?Parcel { return $this->parcel; }
    public function setParcel(?Parcel $parcel): self 
    { 
        $this->parcel = $parcel; 
        return $this; 
    }

    public function getOrderItem(): ?OrderItem { return $this->orderItem; }
    public function setOrderItem(?OrderItem $orderItem): self 
    { 
        $this->orderItem = $orderItem; 
        return $this; 
    }

    public function getQuantity(): int { return $this->quantity; }
    public function setQuantity(int $quantity): self 
    { 
        $this->quantity = $quantity; 
        return $this; 
    }

    /**
     * Nom du produit (pour affichage rapide)
     */
    #[Groups(['parcelItem:read', 'parcel:read'])]
    public function getProductName(): ?string
    {
        return $this->orderItem?->getProduct()?->getName();
    }

    /**
     * Poids unitaire du produit en grammes
     */
    #[Groups(['parcelItem:read', 'parcel:read'])]
    public function getUnitWeightGrams(): int
    {
        $product = $this->orderItem?->getProduct();
        if (!$product) {
            return 500;
        }

        // Utilise getWeight() actuel (sera migré vers getWeightGrams() plus tard)
        $weight = $product->getWeight();
        if ($weight === null || !is_numeric($weight)) {
            return 500; // Même fallback que ParcelManager::getProductWeightGrams
        }

        $weight = (float) $weight;

        // Conversion intelligente (comme dans ColissimoApiService)
        if ($weight > 0 && $weight <= 30) {
            // Valeur en KG (fixtures Faker) → convertir en grammes
            return max(1, (int) round($weight * 1000));
        }

        // Déjà en grammes
        return max(1, (int) round($weight));
    }

    /**
     * Poids total de cet item dans le colis (quantity × poids unitaire)
     */
    #[Groups(['parcelItem:read', 'parcel:read'])]
    public function getTotalWeightGrams(): int
    {
        return $this->getUnitWeightGrams() * $this->quantity;
    }
}