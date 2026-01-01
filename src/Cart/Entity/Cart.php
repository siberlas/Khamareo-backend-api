<?php

namespace App\Cart\Entity;

use App\Cart\Repository\CartRepository;
use Doctrine\ORM\Mapping as ORM;
use ApiPlatform\Metadata\ApiResource;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Serializer\Annotation\Groups;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Delete;
use App\Cart\Controller\GetCurrentCartController;
use App\State\CartProcessor;
use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\GetCollection;
use App\Cart\Controller\MergeGuestCartController;
use App\Order\Dto\GuestCartAddressInput;
use App\Order\State\GuestCartAddressProcessor;
use ApiPlatform\Metadata\Patch;
use App\Dto\GuestShippingAddressPatchInput;
use App\State\GuestShippingAddressPatchProcessor;
use App\Order\State\GuestCheckoutProvider;
use App\Order\Dto\GuestCheckoutView;
use App\User\Entity\User;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: CartRepository::class)]
#[ApiFilter(BooleanFilter::class, properties: ['isActive'])]
#[ApiResource(
    normalizationContext: ['groups' => ['cart:read']],
    denormalizationContext: ['groups' => ['cart:write']],
    operations: [
        new GetCollection(security: "is_granted('ROLE_ADMIN')"), // liste uniquement pour admin
        new Get(
            name: 'get_current_cart',
            uriTemplate: '/cart',
            controller: GetCurrentCartController::class,
            read: false,
            output: Cart::class,
            normalizationContext: ['groups' => ['cart:read']],
            security: "is_granted('PUBLIC_ACCESS')" 
        ),
        new Get(
            security: "is_granted('ROLE_ADMIN') or (object and object.getOwner() and object.getOwner() == user)"
        ),
         new Get(
            uriTemplate: '/guest/{guestToken}',
            output: GuestCheckoutView::class,
            provider: GuestCheckoutProvider::class,
            normalizationContext: ['groups' => ['guest-user']],
            security: "is_granted('PUBLIC_ACCESS')" 
         ),
        new Post(
            security: "is_granted('PUBLIC_ACCESS')",
            processor: CartProcessor::class
        ),
        new Post(
           name: 'merge_guest_cart',
           uriTemplate: '/cart/merge',
           controller: MergeGuestCartController::class,
           deserialize: false,
           input: false,
           security: "is_granted('ROLE_USER')",
        ),
        new Post(
            uriTemplate: '/guest/cart/address',
            input: GuestCartAddressInput::class,
            processor: GuestCartAddressProcessor::class,
            name: 'post_guest_cart_address',
            denormalizationContext: ['groups' => ['guest-user']],
            security: "is_granted('PUBLIC_ACCESS')"

        ),
        new Delete(
            security: "is_granted('ROLE_ADMIN') or (object and object.getOwner() and object.getOwner() == user)"
        )
    ]
)]
class Cart
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['cart:read'])]
    private ?Uuid $id = null;

    #[ORM\Column]
    #[Groups(['cart:read'])]
    private ?\DateTimeImmutable $createdAt = null;


    #[ORM\Column(nullable: true)]
    #[Groups(['cart:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(inversedBy: 'carts')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Groups(['cart:read'])]
    private ?User $owner = null;

    #[ORM\OneToMany(mappedBy: 'cart', targetEntity: CartItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['cart:read', 'cart:write'])]
    private Collection $items;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['cart:read', 'cart:write'])]
    private bool $isActive = true;

    #[ORM\Column(length: 64, nullable: true, unique: true)]
    #[Groups(['cart:read', 'cart:write'])]
    private ?string $guestToken = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['cart:read'])]
    private ?string $paymentIntentId = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['cart:read'])]
    private ?string $paymentClientSecret = null;

    #[ORM\Column(type: 'float', nullable: true)]
    #[Groups(['cart:read', 'cart:write'])]
    private ?float $shippingCost = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['cart:read', 'cart:write'])]
    private ?string $promoCode = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['cart:read'])]
    private ?string $discountAmount = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->items = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    /**
     * @return Collection<int, CartItem>
     */
    public function getItems(): Collection { return $this->items; }

    public function addItem(CartItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setCart($this);
        }
        return $this;
    }

    public function removeItem(CartItem $item): static
    {
        if ($this->items->removeElement($item)) {
            if ($item->getCart() === $this) {
                $item->setCart(null);
            }
        }
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getGuestToken(): ?string
    {
        return $this->guestToken;
    }

    public function setGuestToken(?string $guestToken): static
    {
        $this->guestToken = $guestToken;

        return $this;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Retourne le sous-total des items SANS shipping
     */
    #[Groups(['cart:read'])]
    public function getSubtotal(): float
    {
        $total = 0;
        foreach ($this->getItems() as $item) {
            $total += $item->getQuantity() * $item->getUnitPrice();
        }
        return $total;
    }

    /**
     * Retourne le sous-total APRÈS réduction promo (mais SANS shipping)
     */
    #[Groups(['cart:read'])]
    public function getSubtotalAfterDiscount(): float
    {
        $subtotal = $this->getSubtotal();
        
        if ($this->discountAmount) {
            $subtotal -= (float) $this->discountAmount;
        }
        
        return max(0, $subtotal);
    }

    /**
     * Retourne le TOTAL final (items - promo + shipping)
     */
    #[Groups(['cart:read'])]
    public function getTotalAmount(): float
    {
        $total = $this->getSubtotalAfterDiscount();
        
        // Ajouter les frais de livraison (calculés après choix méthode)
        if ($this->shippingCost) {
            $total += $this->shippingCost;
        }
        
        return max(0, $total);
    }

    // Getters/Setters
    public function getPromoCode(): ?string
    {
        return $this->promoCode;
    }

    public function setPromoCode(?string $promoCode): static
    {
        $this->promoCode = $promoCode;
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

    public function getPaymentIntentId(): ?string
    {
        return $this->paymentIntentId;
    }

    public function setPaymentIntentId(?string $paymentIntentId): self
    {
        $this->paymentIntentId = $paymentIntentId;
        return $this;
    }

    public function getPaymentClientSecret(): ?string
    {
        return $this->paymentClientSecret;
    }

    public function setPaymentClientSecret(?string $paymentClientSecret): self
    {
        $this->paymentClientSecret = $paymentClientSecret;
        return $this;
    }

    public function getShippingCost(): ?float
    {
        return $this->shippingCost;
    }

    public function setShippingCost(?float $shippingCost): self
    {
        $this->shippingCost = $shippingCost;
        return $this;
    }


}
