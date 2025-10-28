<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Delete;
use App\Repository\ShippingAddressRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: ShippingAddressRepository::class)]
#[ApiResource(
    normalizationContext: ['groups' => ['shippingAddress:read']],
    denormalizationContext: ['groups' => ['shippingAddress:write']],
    operations: [
        // Pour l'utilisateur connecté
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('ROLE_ADMIN') or object.getOwner() == user"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "object.getOwner() == user"),
        new Delete(security: "object.getOwner() == user")
    ]
)]
class ShippingAddress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['shippingAddress:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Groups(['shippingAddress:read', 'shippingAddress:write'])]
    private string $firstName;

    #[ORM\Column(length: 100)]
    #[Groups(['shippingAddress:read', 'shippingAddress:write'])]
    private string $lastName;

    #[ORM\Column(length: 255)]
    #[Groups(['shippingAddress:read', 'shippingAddress:write'])]
    private string $streetAddress;

    #[ORM\Column(length: 100)]
    #[Groups(['shippingAddress:read', 'shippingAddress:write'])]
    private string $city;

    #[ORM\Column(length: 10)]
    #[Groups(['shippingAddress:read', 'shippingAddress:write'])]
    private string $postalCode;

    #[ORM\Column(length: 100)]
    #[Groups(['shippingAddress:read', 'shippingAddress:write'])]
    private string $country;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['shippingAddress:read', 'shippingAddress:write'])]
    private ?string $phone = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['shippingAddress:read'])]
    private ?User $owner = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;
        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;
        return $this;
    }

    public function getStreetAddress(): string
    {
        return $this->streetAddress;
    }

    public function setStreetAddress(string $streetAddress): static
    {
        $this->streetAddress = $streetAddress;
        return $this;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function setCity(string $city): static
    {
        $this->city = $city;
        return $this;
    }

    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    public function setPostalCode(string $postalCode): static
    {
        $this->postalCode = $postalCode;
        return $this;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function setCountry(string $country): static
    {
        $this->country = $country;
        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;
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
    
    public function getFullAddress(): string
    {
        return sprintf(
            '%s, %s %s, %s',
            $this->streetAddress,
            $this->postalCode,
            $this->city,
            $this->country
        );
    }
}
