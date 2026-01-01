<?php

// src/Entity/Media.php

namespace App\Media\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Media\Repository\MediaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use App\User\Entity\User;

#[ORM\Entity(repositoryClass: MediaRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    normalizationContext: ['groups' => ['media:read']],
    denormalizationContext: ['groups' => ['media:write']],
    operations: [
        new GetCollection(security: "is_granted('PUBLIC_ACCESS')"),
        new Get(security: "is_granted('PUBLIC_ACCESS')"),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Put(security: "is_granted('ROLE_ADMIN')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ]
)]
#[ApiFilter(SearchFilter::class, properties: [
    'mediaType' => 'exact',
    'folder' => 'partial',
    'tags' => 'partial',
    'altText' => 'partial',
])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'filename', 'fileSize'])]
class Media
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['media:read', 'product:read', 'category:read', 'hero_slide:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Groups(['media:read', 'media:write'])]
    #[Assert\NotBlank]
    private string $cloudinaryPublicId;

    #[ORM\Column(length: 255, unique: true, nullable: true)]
    #[Groups(['media:read'])]
    private ?string $cloudinaryAssetId = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['media:read', 'media:write', 'product:read', 'category:read', 'hero_slide:read'])]
    #[Assert\NotBlank]
    #[Assert\Url]
    private string $url;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['media:read', 'product:read', 'category:read', 'hero_slide:read'])]
    private ?string $thumbnailUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['media:read', 'media:write'])]
    private ?string $filename = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['media:read', 'media:write', 'product:read', 'category:read', 'hero_slide:read'])]
    private ?string $altText = null;

    #[ORM\Column(length: 50)]
    #[Groups(['media:read', 'media:write'])]
    private string $mediaType = 'image';

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['media:read'])]
    private ?string $mimeType = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['media:read', 'product:read', 'hero_slide:read'])]
    private ?int $width = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['media:read', 'product:read', 'hero_slide:read'])]
    private ?int $height = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['media:read'])]
    private ?int $fileSize = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['media:read', 'media:write'])]
    private ?array $tags = [];

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['media:read', 'media:write'])]
    private ?string $folder = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['media:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['media:read'])]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[Groups(['media:read'])]
    private ?User $createdBy = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // Getters & Setters
    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getCloudinaryPublicId(): string
    {
        return $this->cloudinaryPublicId;
    }

    public function setCloudinaryPublicId(string $cloudinaryPublicId): self
    {
        $this->cloudinaryPublicId = $cloudinaryPublicId;

        return $this;
    }

    public function getCloudinaryAssetId(): ?string
    {
        return $this->cloudinaryAssetId;
    }

    public function setCloudinaryAssetId(?string $cloudinaryAssetId): self
    {
        $this->cloudinaryAssetId = $cloudinaryAssetId;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function getThumbnailUrl(): ?string
    {
        return $this->thumbnailUrl;
    }

    public function setThumbnailUrl(?string $thumbnailUrl): self
    {
        $this->thumbnailUrl = $thumbnailUrl;

        return $this;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(?string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    public function getAltText(): ?string
    {
        return $this->altText;
    }

    public function setAltText(?string $altText): self
    {
        $this->altText = $altText;

        return $this;
    }

    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    public function setMediaType(string $mediaType): self
    {
        $this->mediaType = $mediaType;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): self
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function setWidth(?int $width): self
    {
        $this->width = $width;

        return $this;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function setHeight(?int $height): self
    {
        $this->height = $height;

        return $this;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function setFileSize(?int $fileSize): self
    {
        $this->fileSize = $fileSize;

        return $this;
    }

    public function getTags(): ?array
    {
        return $this->tags;
    }

    public function setTags(?array $tags): self
    {
        $this->tags = $tags;

        return $this;
    }

    public function getFolder(): ?string
    {
        return $this->folder;
    }

    public function setFolder(?string $folder): self
    {
        $this->folder = $folder;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }
}
