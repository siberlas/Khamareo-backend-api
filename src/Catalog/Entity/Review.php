<?php

namespace App\Catalog\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Catalog\Repository\ReviewRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: ReviewRepository::class)]
#[ApiResource(
    normalizationContext: ['groups' => ['review:read']],
    denormalizationContext: ['groups' => ['review:write']],
    operations: [
        new GetCollection(security: "is_granted('PUBLIC_ACCESS')"),
        new Get(security: "is_granted('PUBLIC_ACCESS')"),
    ]
)]
class Review
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['review:read', 'product:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['review:read', 'review:write', 'product:read'])]
    private ?string $name = null;

    #[ORM\Column(type: 'smallint')]
    #[Groups(['review:read', 'review:write', 'product:read'])]
    private ?int $rating = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['review:read', 'review:write', 'product:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'text')]
    #[Groups(['review:read', 'review:write', 'product:read'])]
    private ?string $comment = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['review:read', 'review:write'])]
    private ?string $email = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['review:read', 'review:write', 'product:read'])]
    private bool $isVerified = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['review:read', 'product:read'])]
    private bool $isPurchaseVerified = false;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['review:read', 'review:write', 'product:read'])]
    private ?string $adminReply = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['review:read', 'product:read'])]
    private ?\DateTimeImmutable $adminRepliedAt = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'reviews')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Product $product = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getRating(): ?int
    {
        return $this->rating;
    }

    public function setRating(int $rating): self
    {
        $this->rating = $rating;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): self { $this->email = $email; return $this; }

    public function getIsVerified(): bool { return $this->isVerified; }
    public function setIsVerified(bool $isVerified): self { $this->isVerified = $isVerified; return $this; }

    public function getIsPurchaseVerified(): bool { return $this->isPurchaseVerified; }
    public function setIsPurchaseVerified(bool $isPurchaseVerified): self { $this->isPurchaseVerified = $isPurchaseVerified; return $this; }

    public function getAdminReply(): ?string { return $this->adminReply; }
    public function setAdminReply(?string $adminReply): self { $this->adminReply = $adminReply; return $this; }

    public function getAdminRepliedAt(): ?\DateTimeImmutable { return $this->adminRepliedAt; }
    public function setAdminRepliedAt(?\DateTimeImmutable $adminRepliedAt): self { $this->adminRepliedAt = $adminRepliedAt; return $this; }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): self
    {
        $this->product = $product;
        return $this;
    }
}
