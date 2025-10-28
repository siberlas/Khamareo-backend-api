<?php

namespace App\Entity;

use App\Repository\CartItemRepository;
use Doctrine\ORM\Mapping as ORM;
use ApiPlatform\Metadata\ApiResource;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Serializer\Annotation\Groups;
Use ApiPlatform\Metadata\Post;
Use ApiPlatform\Metadata\Patch;
Use ApiPlatform\Metadata\Delete;
use App\State\CartItemProcessor;

#[ORM\Entity(repositoryClass: CartItemRepository::class)]
#[ORM\UniqueConstraint(name: 'cart_product_unique', columns: ['cart_id', 'product_id'])]
#[ApiResource(
    normalizationContext: ['groups' => ['cartitem:read']],
    denormalizationContext: ['groups' => ['cartitem:write']],
    operations: [
        new Post(
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            processor: CartItemProcessor::class
        ),
        new Patch(security: "is_granted('IS_AUTHENTICATED_FULLY')"),
        new Delete(security: "is_granted('IS_AUTHENTICATED_FULLY')")
    ]
)]
class CartItem
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['cart:read','cartitem:read'])]
    private ?Uuid $id = null;

    #[ORM\Column]
    #[Groups(['cart:read','cart:write','cartitem:read','cartitem:write'])]
    private ?int $quantity = null;

    #[ORM\Column]
    #[Groups(['cart:read','cart:write','cartitem:read','cartitem:write'])]
    private ?float $unitPrice = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['cart:read','cart:write','cartitem:read','cartitem:write'])]
    private ?Cart $cart = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['cart:read','cart:write','cartitem:read','cartitem:write'])]
    private ?Product $product = null;

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

    public function getCart(): ?Cart
    {
        return $this->cart;
    }

    public function setCart(?Cart $cart): static
    {
        $this->cart = $cart;

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

    public function getUnitPrice(): ?float { return $this->unitPrice; }

    public function setUnitPrice(float $unitPrice): static { $this->unitPrice = $unitPrice; return $this; }

    #[Groups(['cart:read'])]
    public function getSubtotal(): float
    {
        return $this->quantity * $this->unitPrice;
    }
   
}
