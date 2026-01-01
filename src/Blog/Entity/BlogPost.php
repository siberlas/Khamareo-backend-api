<?php

namespace App\Blog\Entity;

use App\Blog\Repository\BlogPostRepository;
use App\Blog\State\BlogPostProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post as ApiPost;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use App\User\Entity\User;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: BlogPostRepository::class)]
#[ApiResource(
    processor: BlogPostProcessor::class,
    normalizationContext: ['groups' => ['blog_post:read']],
    denormalizationContext: ['groups' => ['blog_post:write']],
    operations: [
        new GetCollection(security: "is_granted('PUBLIC_ACCESS')"),
        new Get(security: "is_granted('PUBLIC_ACCESS')"),
        new ApiPost(security: "is_granted('ROLE_ADMIN')"),
        new Put(security: "is_granted('ROLE_ADMIN')"),
        new Delete(security: "is_granted('ROLE_ADMIN')")
    ]
)]
// Filtres
#[ApiFilter(SearchFilter::class, properties: [
    'title' => 'partial',           // Recherche partielle dans le titre
    'excerpt' => 'partial',          // Recherche partielle dans l'excerpt
    'content' => 'partial',          // Recherche partielle dans le contenu
    'status' => 'exact',             // Filtre exact sur le status
    'slug' => 'exact',               // Filtre exact sur le slug
    'category' => 'exact',           // Filtre par catégorie (ID)
    'author' => 'exact',             // Filtre par auteur (ID)
    'authorName' => 'partial',       // Recherche partielle sur le nom d'auteur
])]
#[ApiFilter(BooleanFilter::class, properties: [
    'isFeatured'                     // Filtre sur les articles à la une
])]
#[ApiFilter(DateFilter::class, properties: [
    'publishedAt',                   // Filtre par date de publication
    'createdAt',                     // Filtre par date de création
])]
#[ApiFilter(OrderFilter::class, properties: [
    'publishedAt',                   // Tri par date de publication
    'createdAt',                     // Tri par date de création
    'title',                         // Tri par titre
    'readingTime',                   // Tri par temps de lecture
], arguments: ['orderParameterName' => 'order'])]
class BlogPost
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['blog_post:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['blog_post:read', 'blog_post:write'])]
    #[Assert\NotBlank(message: 'Le titre est obligatoire')]
    #[Assert\Length(
        min: 3,
        max: 255,
        minMessage: 'Le titre doit contenir au moins {{ limit }} caractères',
        maxMessage: 'Le titre ne peut pas dépasser {{ limit }} caractères'
    )]
    private ?string $title = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Groups(['blog_post:read', 'blog_post:write'])]
    private ?string $slug = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['blog_post:read', 'blog_post:write'])]
    #[Assert\Length(
        max: 500,
        maxMessage: 'L\'excerpt ne peut pas dépasser {{ limit }} caractères'
    )]
    private ?string $excerpt = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['blog_post:read', 'blog_post:write'])]
    #[Assert\NotBlank(message: 'Le contenu est obligatoire')]
    private ?string $content = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['blog_post:read', 'blog_post:write'])]
    #[Assert\Url(message: 'URL invalide')]
    #[Assert\Regex(
        pattern: '/^https:\/\/res\.cloudinary\.com\/[a-z0-9-]+\/image\/upload\//',
        message: 'L\'image doit provenir de Cloudinary'
    )]
    private ?string $featuredImage = null;

    #[ORM\Column(length: 20)]
    #[Groups(['blog_post:read', 'blog_post:write'])]
    #[Assert\Choice(choices: ['draft', 'published'], message: 'Le statut doit être "draft" ou "published"')]
    private string $status = 'draft';

    #[ORM\Column]
    #[Groups(['blog_post:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['blog_post:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['blog_post:read', 'blog_post:write'])]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['blog_post:read', 'blog_post:write'])]
    private ?int $readingTime = null;

    #[ORM\Column(type: 'boolean')]
    #[Groups(['blog_post:read', 'blog_post:write'])]
    private bool $isFeatured = false;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['blog_post:read', 'blog_post:write'])]
    private ?string $authorName = null;

    #[ORM\ManyToOne(inversedBy: 'blogPosts')]
    #[Groups(['blog_post:read', 'blog_post:write'])]
    private ?User $author = null;

    #[ORM\ManyToOne(targetEntity: BlogCategory::class, inversedBy: 'blogPosts')]
    #[Groups(['blog_post:read', 'blog_post:write'])]
    private ?BlogCategory $category = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        
        if ($this->status === 'published' && !$this->publishedAt) {
            $this->publishedAt = new \DateTimeImmutable();
        }
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
        
        if ($this->status === 'published' && !$this->publishedAt) {
            $this->publishedAt = new \DateTimeImmutable();
        }
    }

    // Getters/Setters (inchangés)
    
    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }

    public function getExcerpt(): ?string
    {
        return $this->excerpt;
    }

    public function setExcerpt(?string $excerpt): static
    {
        $this->excerpt = $excerpt;
        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function getFeaturedImage(): ?string
    {
        return $this->featuredImage;
    }

    public function setFeaturedImage(?string $featuredImage): static
    {
        $this->featuredImage = $featuredImage;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;
        return $this;
    }

    public function getReadingTime(): ?int
    {
        return $this->readingTime;
    }

    public function setReadingTime(?int $readingTime): static
    {
        $this->readingTime = $readingTime;
        return $this;
    }

    public function isFeatured(): bool
    {
        return $this->isFeatured;
    }

    public function setIsFeatured(bool $isFeatured): static
    {
        $this->isFeatured = $isFeatured;
        return $this;
    }

    public function getAuthorName(): ?string
    {
        return $this->authorName;
    }

    public function setAuthorName(?string $authorName): static
    {
        $this->authorName = $authorName;
        return $this;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): static
    {
        $this->author = $author;
        return $this;
    }

    public function getCategory(): ?BlogCategory
    {
        return $this->category;
    }

    public function setCategory(?BlogCategory $category): static
    {
        $this->category = $category;
        return $this;
    }
}