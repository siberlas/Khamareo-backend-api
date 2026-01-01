<?php

namespace App\Cart\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Delete;
use App\Cart\Repository\FavoriteRepository;
use App\Cart\State\FavoriteProcessor;
use App\Cart\State\FavoriteCollectionProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use App\User\Entity\User;
use App\Catalog\Entity\Product;

#[ORM\Entity(repositoryClass: FavoriteRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'owner_product_favorite_unique', columns: ['owner_id', 'product_id'])]
#[UniqueEntity(
    fields: ['owner', 'product'],
    message: 'Ce produit est déjà dans vos favoris.'
)]
#[ApiResource(
    normalizationContext: ['groups' => ['favorite:read']],
    denormalizationContext: ['groups' => ['favorite:write']],
    operations: [
        // Liste des favoris de l'utilisateur connecté
        new GetCollection(
            security: "is_granted('ROLE_USER')",
            provider: FavoriteCollectionProvider::class
        ),
        
        // Ajouter un favori
        new Post(
            security: "is_granted('ROLE_USER')",
            processor: FavoriteProcessor::class
        ),
        
        // Supprimer un favori (propriétaire seulement)
        new Delete(
            security: "is_granted('ROLE_USER') and object.getOwner() == user"
        )
    ]
)]
class Favorite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['favorite:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['favorite:read'])]
    private User $owner;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['favorite:read', 'favorite:write', 'product:read'])]
    private Product $product;

    #[ORM\Column]
    #[Groups(['favorite:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // Getters/Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): static
    {
        $this->owner = $owner;
        return $this;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function setProduct(Product $product): static
    {
        $this->product = $product;
        return $this;
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
}