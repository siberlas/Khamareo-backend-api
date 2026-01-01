<?php

namespace App\Shipping\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Delete;
use App\Shipping\Repository\ShippingMethodRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use App\Shipping\Controller\ShippingCostController;

#[ORM\Entity(repositoryClass: ShippingMethodRepository::class)]
#[ApiResource(
    normalizationContext: ['groups' => ['shippingMethod:read']],
    denormalizationContext: ['groups' => ['shippingMethod:write']],
    operations: [
        // Public read access (clients)
        new GetCollection(security: "is_granted('PUBLIC_ACCESS')"),
        new Get(security: "is_granted('PUBLIC_ACCESS')"),

        // Admin management
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Post(
            name: 'calculate_shipping',
            uriTemplate: '/shipping_methods/{id}/calculate',
            controller: ShippingCostController::class,
            security: "is_granted('PUBLIC_ACCESS')",
            read: false,
            write: false,
            
        ),
        new Patch(security: "is_granted('ROLE_ADMIN')"),
        new Delete(security: "is_granted('ROLE_ADMIN')")
    ]
)]
class ShippingMethod
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['shippingMethod:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['shippingMethod:read', 'shippingMethod:write'])]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['shippingMethod:read', 'shippingMethod:write'])]
    private ?string $description = null;

    #[ORM\Column(type: 'float')]
    #[Groups(['shippingMethod:read', 'shippingMethod:write'])]
    private float $price;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['shippingMethod:read', 'shippingMethod:write'])]
    private ?string $carrierCode = null; // Ex: "LA_POSTE", "MONDIAL_RELAY"




    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function setPrice(float $price): static
    {
        $this->price = $price;
        return $this;
    }

    public function getCarrierCode(): ?string
    {
        return $this->carrierCode;
    }

    public function setCarrierCode(?string $carrierCode): static
    {
        $this->carrierCode = $carrierCode;
        return $this;
    }

}
