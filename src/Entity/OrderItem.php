<?php

namespace App\Entity;

use App\Repository\OrderItemRepository;
use Doctrine\ORM\Mapping as ORM;
use ApiPlatform\Metadata\ApiResource;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Serializer\Annotation\Groups;

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

}
