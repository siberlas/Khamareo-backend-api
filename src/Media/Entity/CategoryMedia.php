<?php

// src/Entity/CategoryMedia.php

namespace App\Media\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use App\Catalog\Entity\Category;

#[ORM\Entity]
#[ORM\Table(name: 'category_media')]
#[ApiResource(
    normalizationContext: ['groups' => ['category_media:read']],
    security: "is_granted('ROLE_ADMIN')"
)]
class CategoryMedia
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['category_media:read', 'category:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Category $category;

    #[ORM\ManyToOne(targetEntity: Media::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['category_media:read', 'category:read'])]
    private Media $media;

    #[ORM\Column(length: 50)]
    #[Groups(['category_media:read', 'category:read'])]
    private string $mediaUsage = 'main'; // 'main', 'banner', 'icon'

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    // Getters & Setters
    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function setCategory(Category $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getMedia(): Media
    {
        return $this->media;
    }

    public function setMedia(Media $media): self
    {
        $this->media = $media;

        return $this;
    }

    public function getMediaUsage(): string
    {
        return $this->mediaUsage;
    }

    public function setMediaUsage(string $mediaUsage): self
    {
        $this->mediaUsage = $mediaUsage;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
