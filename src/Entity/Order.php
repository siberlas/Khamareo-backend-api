<?php

namespace App\Entity;

use App\Repository\OrderRepository;
use Doctrine\ORM\Mapping as ORM;
use ApiPlatform\Metadata\ApiResource;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Serializer\Annotation\Groups;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use ApiPlatform\Metadata\Post; 
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use App\Controller\CheckoutController;
use App\State\OrderProvider;
use App\Entity\ShippingMethod;
use App\Entity\Address;
use App\Enum\OrderStatus;
use Symfony\Component\Validator\Constraints as Assert;
use App\Controller\OrderByPaymentIntentController;
use App\Controller\PublicOrderByNumberController;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: '`order`')]
#[ApiResource(
    normalizationContext: ['groups' => ['order:read']],
    denormalizationContext: ['groups' => ['order:write']],
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_ADMIN') or is_granted('ROLE_USER')",
            provider: OrderProvider::class,
            normalizationContext: ['groups' => ['order:read']]
        ),
        new Get(
            security: "is_granted('ROLE_ADMIN') or object.getOwner() == user",
            normalizationContext: ['groups' => ['order:read']]
        ),
        // ⚠️ Option B : checkout accessible aux invités ET aux connectés.
        // Le contrôleur vérifiera user OU guestToken + infos invité.
        new Get(
            uriTemplate: '/orders/by-payment-intent/{piId}',
            controller: OrderByPaymentIntentController::class,
            read: false,
            deserialize: false,
            name: 'order_by_payment_intent',
            security: "is_granted('PUBLIC_ACCESS')"
        ),
        new Get(
            uriTemplate: '/orders/public/{orderNumber}',
            controller: PublicOrderByNumberController::class,
            read: false,
            deserialize: false,
            name: 'order_public_by_number',
            security: "is_granted('PUBLIC_ACCESS')"
        ),
        new Post(
            uriTemplate: '/cart/checkout',
            controller: CheckoutController::class,
            security: "is_granted('PUBLIC_ACCESS')",
            deserialize: false,
            validate: false    
        ),
        new Patch(
            security: "is_granted('ROLE_ADMIN') or object.getOwner() == user",
            denormalizationContext: ['groups' => ['order:write']]
        ),
    ]
)]
class Order
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['order:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: 30, unique: true)]
    #[Groups(['order:read'])]
    private ?string $reference = null;

    #[ORM\Column]
    #[Groups(['order:read', 'order:write'])]
    private ?float $totalAmount = null;

    #[Assert\NotBlank]
    #[ORM\Column(type: 'string', enumType: OrderStatus::class)]
    #[Groups(['order:read', 'order:write'])]
    private OrderStatus $status = OrderStatus::PENDING;

     // ===== 🆕 SNAPSHOT ADDRESSES =====
    #[ORM\ManyToOne(targetEntity: Address::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['order:read', 'order:write'])]
    private ?Address $shippingAddress = null;

    #[ORM\ManyToOne(targetEntity: Address::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['order:read', 'order:write'])]
    private ?Address $billingAddress = null;
    // =================================

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['order:read'])]
    private ?string $paymentId = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['order:read','order:write'])]
    private ?string $paymentMethod = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Groups(['order:read'])]
    private ?string $paymentStatus = 'unpaid';

    #[ORM\Column(nullable: true)]
    #[Groups(['order:read'])]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['order:read'])]
    private ?string $trackingNumber = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['order:read'])]
    private ?\DateTimeImmutable $shippedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['order:read'])]
    private ?\DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['order:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: 'boolean')]
    #[Groups(['order:read'])]
    private bool $isLocked = false;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['order:read','order:write'])]
    private ?string $customerNote = null;

    #[ORM\Column]
    #[Groups(['order:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    // ⚠️ Devient nullable pour les invités
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['order:read'])]
    private ?User $owner = null;

    #[ORM\ManyToOne(targetEntity: ShippingMethod::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['order:read', 'order:write'])]
    private ?ShippingMethod $shippingMethod = null;

    #[ORM\OneToMany(mappedBy: 'customerOrder', targetEntity: OrderItem::class, cascade: ['persist'], orphanRemoval: true)]
    #[Groups(['order:read','order:write'])]
    private Collection $items;

    #[ORM\Column(length: 20, unique: true)]
    #[Groups(['order:read'])]
    private ?string $orderNumber = null;

    #[ORM\OneToOne(mappedBy: 'order', targetEntity: Payment::class, cascade: ['persist', 'remove'])]
    #[Groups(['order:read'])]
    private ?Payment $payment = null;

    #[ORM\Column(length: 3)]
    #[Groups(['order:read', 'order:write'])]
    private string $currency = 'EUR';

    #[ORM\OneToOne(mappedBy: 'order', cascade: ['persist', 'remove'])]
    #[Groups(['order:read'])]
    private ?ShippingLabel $shippingLabel = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $shippingCost = null;

    // ============ 🔹 Champs “Guest checkout” (nouveaux) ============
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    #[Groups(['order:read','order:write'])]
    private ?string $guestEmail = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['order:read','order:write'])]
    private ?string $guestFirstName = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['order:read','order:write'])]
    private ?string $guestLastName = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Groups(['order:read','order:write'])]
    private ?string $guestPhone = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isRelayPoint = false;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $relayPointId = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $relayCarrier = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['order:read', 'order:write'])]
    private ?string $promoCode = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['order:read'])]
    private ?string $discountAmount = null;

    // Règle : soit owner, soit guestEmail doit être présent
    #[Assert\Expression(
        "this.getOwner() !== null || (this.getGuestEmail() !== null && this.getGuestEmail() !== '')",
        message: "Un compte ou une adresse e-mail invitée est requis pour passer commande."
    )]
    private ?bool $dummyValidation = null;
    // ===============================================================

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->reference = 'ORD-' . strtoupper(bin2hex(random_bytes(4)));
        $this->items = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;

        if (!$this->orderNumber) {
            $this->orderNumber = 'CMD-' . $now->format('Ymd') . '-' . random_int(1000, 9999);
        }
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // --- Getters / Setters (inchangés + nouveaux) ---
    public function getId(): ?Uuid { return $this->id; }
    public function getReference(): ?string { return $this->reference; }

    public function getTotalAmount(): ?float { return $this->totalAmount; }
    public function setTotalAmount(float $totalAmount): static { $this->totalAmount = $totalAmount; return $this; }

    public function getStatus(): OrderStatus { return $this->status; }
    public function setStatus(OrderStatus $status): static { $this->status = $status; return $this; }

    #[Groups(['order:read'])]
    public function getStatusLabel(): string { return $this->status->label(); }

    public function getPaymentId(): ?string { return $this->paymentId; }
    public function setPaymentId(?string $paymentId): static { $this->paymentId = $paymentId; return $this; }

    public function getPaymentMethod(): ?string { return $this->paymentMethod; }
    public function setPaymentMethod(?string $paymentMethod): static { $this->paymentMethod = $paymentMethod; return $this; }

    public function getPaymentStatus(): ?string { return $this->paymentStatus; }
    public function setPaymentStatus(?string $paymentStatus): static { $this->paymentStatus = $paymentStatus; return $this; }

    // ===== SNAPSHOT getters/setters =====
    public function getShippingAddress(): ?Address { return $this->shippingAddress; }
    public function setShippingAddress(?Address $address): static { $this->shippingAddress = $address; return $this; }

    public function getBillingAddress(): ?Address { return $this->billingAddress; }
    public function setBillingAddress(?Address $address): static { $this->billingAddress = $address; return $this; }

    
    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function setPaidAt(?\DateTimeImmutable $paidAt): static { $this->paidAt = $paidAt; return $this; }

    public function getShippingMethod(): ?ShippingMethod { return $this->shippingMethod; }
    public function setShippingMethod(?ShippingMethod $shippingMethod): static { $this->shippingMethod = $shippingMethod; return $this; }

    public function getTrackingNumber(): ?string { return $this->trackingNumber; }
    public function setTrackingNumber(?string $trackingNumber): static { $this->trackingNumber = $trackingNumber; return $this; }

    public function getShippedAt(): ?\DateTimeImmutable { return $this->shippedAt; }
    public function setShippedAt(?\DateTimeImmutable $shippedAt): static { $this->shippedAt = $shippedAt; return $this; }

    public function getDeliveredAt(): ?\DateTimeImmutable { return $this->deliveredAt; }
    public function setDeliveredAt(?\DateTimeImmutable $deliveredAt): static { $this->deliveredAt = $deliveredAt; return $this; }

    public function getCustomerNote(): ?string { return $this->customerNote; }
    public function setCustomerNote(?string $customerNote): static { $this->customerNote = $customerNote; return $this; }

    public function isLocked(): bool { return $this->isLocked; }
    public function setIsLocked(bool $isLocked): static { $this->isLocked = $isLocked; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    public function getOwner(): ?User { return $this->owner; }
    public function setOwner(?User $owner): static { $this->owner = $owner; return $this; }

    /** @return Collection<int, OrderItem> */
    public function getItems(): Collection { return $this->items; }
    public function addItem(OrderItem $item): static {
        if (!$this->items->contains($item)) { $this->items->add($item); $item->setCustomerOrder($this); }
        return $this;
    }
    public function removeItem(OrderItem $item): static {
        if ($this->items->removeElement($item)) { if ($item->getCustomerOrder() === $this) { $item->setCustomerOrder(null); } }
        return $this;
    }

    public function getPayment(): ?Payment { return $this->payment; }
    public function setPayment(?Payment $payment): static
    {
        $this->payment = $payment;
        if ($payment && $payment->getOrder() !== $this) {
            $payment->setOrder($this);
        }
        return $this;
    }
    public function getOrderNumber(): ?string { return $this->orderNumber; }
    public function setOrderNumber(?string $orderNumber): static { $this->orderNumber = $orderNumber; return $this; }

    #[Groups(['order:read'])]
    public function getOwnerIri(): ?string { return $this->owner ? '/api/users/' . $this->owner->getId() : null; }

    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): static { $this->currency = strtoupper($currency); return $this; }

    public function getShippingLabel(): ?ShippingLabel { return $this->shippingLabel; }
    public function setShippingLabel(ShippingLabel $shippingLabel): self { $this->shippingLabel = $shippingLabel; return $this; }

    public function getShippingCost(): ?float { return $this->shippingCost; }
    public function setShippingCost(?float $shippingCost): self { $this->shippingCost = $shippingCost; return $this; }

    // --- Getters / setters invités
    public function getGuestEmail(): ?string { return $this->guestEmail; }
    public function setGuestEmail(?string $guestEmail): self { $this->guestEmail = $guestEmail; return $this; }

    public function getGuestFirstName(): ?string { return $this->guestFirstName; }
    public function setGuestFirstName(?string $guestFirstName): self { $this->guestFirstName = $guestFirstName; return $this; }

    public function getGuestLastName(): ?string { return $this->guestLastName; }
    public function setGuestLastName(?string $guestLastName): self { $this->guestLastName = $guestLastName; return $this; }

    public function getGuestPhone(): ?string { return $this->guestPhone; }
    public function setGuestPhone(?string $guestPhone): self { $this->guestPhone = $guestPhone; return $this; }

    public function isRelayPoint(): bool
    {
        return $this->isRelayPoint;
    }

    public function setIsRelayPoint(bool $isRelayPoint): self
    {
        $this->isRelayPoint = $isRelayPoint;
        return $this;
    }

    public function getRelayPointId(): ?string
    {
        return $this->relayPointId;
    }

    public function setRelayPointId(?string $relayPointId): self
    {
        $this->relayPointId = $relayPointId;
        return $this;
    }

    public function getRelayCarrier(): ?string
    {
        return $this->relayCarrier;
    }

    public function setRelayCarrier(?string $relayCarrier): self
    {
        $this->relayCarrier = $relayCarrier;
        return $this;
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

}
