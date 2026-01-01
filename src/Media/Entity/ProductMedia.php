<?php
// src/Entity/ProductMedia.php

namespace App\Media\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use App\Catalog\Entity\Product;

#[ORM\Entity]
#[ORM\Table(name: 'product_media')]
#[ApiResource(
    normalizationContext: ['groups' => ['product_media:read']],
    security: "is_granted('ROLE_ADMIN')"
)]
class ProductMedia
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['product_media:read', 'product:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\ManyToOne(targetEntity: Media::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['product_media:read', 'product:read'])]
    private Media $media;

    #[ORM\Column]
    #[Groups(['product_media:read', 'product:read'])]
    private int $displayOrder = 0;

    #[ORM\Column(type: 'boolean')]
    #[Groups(['product_media:read', 'product:read'])]
    private bool $isPrimary = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    // Getters & Setters
    public function getId(): ?Uuid { return $this->id; }

    public function getProduct(): Product { return $this->product; }
    public function setProduct(Product $product): self
    {
        $this->product = $product;
        return $this;
    }

    public function getMedia(): Media { return $this->media; }
    public function setMedia(Media $media): self
    {
        $this->media = $media;
        return $this;
    }

    public function getDisplayOrder(): int { return $this->displayOrder; }
    public function setDisplayOrder(int $displayOrder): self
    {
        $this->displayOrder = $displayOrder;
        return $this;
    }

    public function isPrimary(): bool { return $this->isPrimary; }
    public function setIsPrimary(bool $isPrimary): self
    {
        $this->isPrimary = $isPrimary;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}