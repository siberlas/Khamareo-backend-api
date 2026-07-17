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

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $carrierShippingCost = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $paymentLastError = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['cart:read', 'cart:write'])]
    private ?string $promoCode = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['cart:read'])]
    private ?string $discountAmount = null;

    /**
     * Stocke tous les codes promo appliqués avec leur réduction individuelle.
     * Format : [['code' => 'PROMO10', 'discount' => 5.00, 'stackable' => true], ...]
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['cart:read'])]
    private ?array $promoCodesData = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $guestCountry = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $guestReferrer = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $osName = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $deviceType = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastReminderAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $reminderCount = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastGuestReminderAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $guestReminderCount = 0;

    /** Étape atteinte dans la séquence de relance : 0 (aucune), 1, 2 ou 3. */
    #[ORM\Column(options: ['default' => 0])]
    private int $reminderStage = 0;

    /**
     * Date d'envoi de la dernière étape — sert de référence pour calculer la
     * suivante. Ne pas confondre avec `updatedAt`, qui est réinitialisé par
     * onPreUpdate() à chaque écriture (y compris celles de cette séquence).
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reminderStageLastSentAt = null;

    /** Code promo -10% associé à l'étape 3 (généré ou réutilisé). */
    #[ORM\Column(length: 36, nullable: true)]
    private ?string $reminderPromoCodeId = null;

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

    public function getLastReminderAt(): ?\DateTimeImmutable { return $this->lastReminderAt; }
    public function setLastReminderAt(?\DateTimeImmutable $lastReminderAt): static { $this->lastReminderAt = $lastReminderAt; return $this; }

    public function getReminderCount(): int { return $this->reminderCount; }
    public function setReminderCount(int $reminderCount): static { $this->reminderCount = $reminderCount; return $this; }

    public function getLastGuestReminderAt(): ?\DateTimeImmutable { return $this->lastGuestReminderAt; }
    public function setLastGuestReminderAt(?\DateTimeImmutable $lastGuestReminderAt): static { $this->lastGuestReminderAt = $lastGuestReminderAt; return $this; }

    public function getGuestReminderCount(): int { return $this->guestReminderCount; }
    public function setGuestReminderCount(int $guestReminderCount): static { $this->guestReminderCount = $guestReminderCount; return $this; }

    public function getReminderStage(): int { return $this->reminderStage; }
    public function setReminderStage(int $reminderStage): static { $this->reminderStage = $reminderStage; return $this; }

    public function getReminderStageLastSentAt(): ?\DateTimeImmutable { return $this->reminderStageLastSentAt; }
    public function setReminderStageLastSentAt(?\DateTimeImmutable $reminderStageLastSentAt): static { $this->reminderStageLastSentAt = $reminderStageLastSentAt; return $this; }

    public function getReminderPromoCodeId(): ?string { return $this->reminderPromoCodeId; }
    public function setReminderPromoCodeId(?string $reminderPromoCodeId): static { $this->reminderPromoCodeId = $reminderPromoCodeId; return $this; }

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

    public function getCarrierShippingCost(): ?float { return $this->carrierShippingCost; }
    public function setCarrierShippingCost(?float $cost): self { $this->carrierShippingCost = $cost; return $this; }

    public function getPaymentLastError(): ?string { return $this->paymentLastError; }
    public function setPaymentLastError(?string $error): self { $this->paymentLastError = $error; return $this; }

    public function getPromoCodesData(): ?array
    {
        return $this->promoCodesData;
    }

    public function setPromoCodesData(?array $promoCodesData): static
    {
        $this->promoCodesData = $promoCodesData;
        return $this;
    }

    /**
     * Retourne la liste des codes appliqués (pour compatibilité frontend).
     * Préfère promoCodesData, fall-back sur promoCode unique.
     */
    #[Groups(['cart:read'])]
    public function getPromoCodes(): array
    {
        if (!empty($this->promoCodesData)) {
            return array_column($this->promoCodesData, 'code');
        }
        return $this->promoCode ? [$this->promoCode] : [];
    }

    public function getGuestCountry(): ?string { return $this->guestCountry; }
    public function setGuestCountry(?string $guestCountry): static { $this->guestCountry = $guestCountry; return $this; }

    public function getGuestReferrer(): ?string { return $this->guestReferrer; }
    public function setGuestReferrer(?string $guestReferrer): static { $this->guestReferrer = $guestReferrer; return $this; }

    public function getOsName(): ?string { return $this->osName; }
    public function setOsName(?string $osName): static { $this->osName = $osName; return $this; }

    public function getDeviceType(): ?string { return $this->deviceType; }
    public function setDeviceType(?string $deviceType): static { $this->deviceType = $deviceType; return $this; }

}
