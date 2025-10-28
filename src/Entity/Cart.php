<?php

namespace App\Entity;

use App\Repository\CartRepository;
use Doctrine\ORM\Mapping as ORM;
use ApiPlatform\Metadata\ApiResource;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Serializer\Annotation\Groups;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Delete;
use App\Controller\GetCurrentCartController;
use App\State\CartProcessor;
use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\GetCollection;

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
            security: "is_granted('PUBLIC_ACCESS') or (object and object.getOwner() and object.getOwner() == user)"
        ),
        new Get(
            security: "is_granted('ROLE_ADMIN') or (object and object.getOwner() and object.getOwner() == user)"
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            processor: CartProcessor::class
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

    public function setOwner(User $owner): static
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

    #[Groups(['cart:read'])]
    public function getTotalAmount(): float
    {
        $total = 0;
        foreach ($this->items as $item) {
            $total += $item->getQuantity() * $item->getUnitPrice();
        }
        return $total;
    }
}
