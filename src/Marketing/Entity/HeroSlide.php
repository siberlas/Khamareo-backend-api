<?php
// src/Entity/HeroSlide.php

namespace App\Marketing\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use App\Media\Entity\Media;

#[ORM\Entity]
#[ORM\Table(name: 'hero_slide')]
#[ApiResource(
    normalizationContext: ['groups' => ['hero_slide:read']],
    denormalizationContext: ['groups' => ['hero_slide:write']],
    operations: [
        new GetCollection(security: "is_granted('PUBLIC_ACCESS')"),
        new Get(security: "is_granted('PUBLIC_ACCESS')"),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Put(security: "is_granted('ROLE_ADMIN')"),
        new Delete(security: "is_granted('ROLE_ADMIN')")
    ]
)]
class HeroSlide
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['hero_slide:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Media::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[Groups(['hero_slide:read', 'hero_slide:write'])]
    private ?Media $media = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['hero_slide:read', 'hero_slide:write'])]
    private ?string $titleKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['hero_slide:read', 'hero_slide:write'])]
    private ?string $subtitleKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['hero_slide:read', 'hero_slide:write'])]
    private ?string $descriptionKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['hero_slide:read', 'hero_slide:write'])]
    private ?string $ctaKey = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['hero_slide:read', 'hero_slide:write'])]
    private ?string $ctaLink = null;

    #[ORM\Column]
    #[Groups(['hero_slide:read', 'hero_slide:write'])]
    private int $displayOrder = 0;

    #[ORM\Column(type: 'boolean')]
    #[Groups(['hero_slide:read', 'hero_slide:write'])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['hero_slide:read', 'hero_slide:write'])]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['hero_slide:read', 'hero_slide:write'])]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['hero_slide:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['hero_slide:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    // Getters & Setters
    public function getId(): ?Uuid { return $this->id; }

    public function getMedia(): ?Media { return $this->media; }
    public function setMedia(?Media $media): self
    {
        $this->media = $media;
        return $this;
    }

    public function getTitleKey(): ?string { return $this->titleKey; }
    public function setTitleKey(?string $titleKey): self
    {
        $this->titleKey = $titleKey;
        return $this;
    }

    public function getSubtitleKey(): ?string { return $this->subtitleKey; }
    public function setSubtitleKey(?string $subtitleKey): self
    {
        $this->subtitleKey = $subtitleKey;
        return $this;
    }

    public function getDescriptionKey(): ?string { return $this->descriptionKey; }
    public function setDescriptionKey(?string $descriptionKey): self
    {
        $this->descriptionKey = $descriptionKey;
        return $this;
    }

    public function getCtaKey(): ?string { return $this->ctaKey; }
    public function setCtaKey(?string $ctaKey): self
    {
        $this->ctaKey = $ctaKey;
        return $this;
    }

    public function getCtaLink(): ?string { return $this->ctaLink; }
    public function setCtaLink(?string $ctaLink): self
    {
        $this->ctaLink = $ctaLink;
        return $this;
    }

    public function getDisplayOrder(): int { return $this->displayOrder; }
    public function setDisplayOrder(int $displayOrder): self
    {
        $this->displayOrder = $displayOrder;
        return $this;
    }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getStartDate(): ?\DateTimeImmutable { return $this->startDate; }
    public function setStartDate(?\DateTimeImmutable $startDate): self
    {
        $this->startDate = $startDate;
        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable { return $this->endDate; }
    public function setEndDate(?\DateTimeImmutable $endDate): self
    {
        $this->endDate = $endDate;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}