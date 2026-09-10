<?php

namespace App\Order\Entity;

use App\Order\Repository\OrderItemRepository;
use Doctrine\ORM\Mapping as ORM;
use ApiPlatform\Metadata\ApiResource;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Serializer\Annotation\Groups;
use App\Catalog\Entity\Product;
use App\Catalog\Enum\ProductType;

#[ORM\Entity(repositoryClass: OrderItemRepository::class)]
#[ApiResource(
    normalizationContext: ['groups' => ['orderitem:read']],
    denormalizationContext: ['groups' => ['orderitem:write']]
)]
class OrderItem
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['order:read','orderitem:read'])]
    private ?Uuid $id = null;

    #[ORM\Column]
    #[Groups(['order:read','order:write','orderitem:read','orderitem:write'])]
    private ?int $quantity = null;

    #[ORM\Column]
    #[Groups(['order:read','order:write','orderitem:read','orderitem:write'])]
    private ?float $unitPrice = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Order $customerOrder = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['order:read','order:write','orderitem:read','orderitem:write'])]
    private ?Product $product = null;

    /**
     * Nature du produit au moment de la commande (le produit peut changer de
     * type après). Sert à router le traitement : expédition vs livraison
     * numérique. Nullable pour les commandes antérieures à la migration.
     */
    #[ORM\Column(type: 'string', length: 20, enumType: ProductType::class, nullable: true)]
    #[Groups(['order:read','orderitem:read'])]
    private ?ProductType $productType = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getUnitPrice(): ?float { return $this->unitPrice; }

    public function setUnitPrice(float $unitPrice): static { $this->unitPrice = $unitPrice; return $this; }

    public function getCustomerOrder(): ?Order
    {
        return $this->customerOrder;
    }

    public function setCustomerOrder(?Order $customerOrder): static
    {
        $this->customerOrder = $customerOrder;

        return $this;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function getProductType(): ?ProductType
    {
        // Repli sur le type courant du produit pour les commandes antérieures
        // à l'ajout de la colonne (snapshot absent).
        return $this->productType ?? $this->product?->getProductType();
    }

    public function setProductType(?ProductType $productType): static
    {
        $this->productType = $productType;

        return $this;
    }

    #[Groups(['order:read','orderitem:read'])]
    public function isDigital(): bool
    {
        return $this->getProductType() === ProductType::DIGITAL;
    }

    public function requiresShipping(): bool
    {
        return $this->getProductType()?->requiresShipping() ?? true;
    }
}
