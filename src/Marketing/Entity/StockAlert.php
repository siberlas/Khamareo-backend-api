<?php

namespace App\Marketing\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Delete;
use App\Marketing\Repository\StockAlertRepository;
use App\Marketing\State\StockAlertProcessor;
use App\Marketing\State\StockAlertCollectionProvider;
use App\User\Entity\User;
use App\Catalog\Entity\Product;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: StockAlertRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'owner_product_unique', columns: ['owner_id', 'product_id'])]
#[UniqueEntity(
    fields: ['owner', 'product'],
    message: 'Vous avez déjà une alerte active pour ce produit.'
)]
#[ApiResource(
    normalizationContext: ['groups' => ['alert:read']],
    denormalizationContext: ['groups' => ['alert:write']],
    operations: [
        // Liste des alertes de l'utilisateur connecté
        new GetCollection(
            security: "is_granted('ROLE_USER')",
            provider: StockAlertCollectionProvider::class
        ),
        
        // Détail d'une alerte (propriétaire seulement)
        new Get(
            security: "is_granted('ROLE_USER') and object.getOwner() == user"
        ),
        
        // Créer une alerte
        new Post(
            security: "is_granted('ROLE_USER')",
            processor: StockAlertProcessor::class
        ),
        
        // Supprimer une alerte (propriétaire seulement)
        new Delete(
            security: "is_granted('ROLE_USER') and object.getOwner() == user"
        )
    ]
)]
class StockAlert
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['alert:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['alert:read'])]
    private User $owner;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['alert:read', 'alert:write'])]
    private Product $product;

    #[ORM\Column]
    #[Groups(['alert:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'boolean')]
    #[Groups(['alert:read'])]
    private bool $notified = false;

    #[ORM\Column(nullable: true)]
    #[Groups(['alert:read'])]
    private ?\DateTimeImmutable $notifiedAt = null;

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

    public function isNotified(): bool
    {
        return $this->notified;
    }

    public function setNotified(bool $notified): static
    {
        $this->notified = $notified;
        return $this;
    }

    public function getNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->notifiedAt;
    }

    public function setNotifiedAt(?\DateTimeImmutable $notifiedAt): static
    {
        $this->notifiedAt = $notifiedAt;
        return $this;
    }
}