<?php

namespace App\Blog\Entity;

use App\Blog\Repository\BlogCommentRepository;
use Doctrine\ORM\Mapping as ORM;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post as ApiPost;
use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: BlogCommentRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    normalizationContext: ['groups' => ['blog_comment:read']],
    denormalizationContext: ['groups' => ['blog_comment:write']],
    operations: [
        new GetCollection(security: "is_granted('PUBLIC_ACCESS')"),
        new ApiPost(security: "is_granted('PUBLIC_ACCESS')"),
    ]
)]
#[ApiFilter(BooleanFilter::class, properties: ['isApproved'])]
#[ApiFilter(SearchFilter::class, properties: ['blogPost' => 'exact', 'blogPost.slug' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt'], arguments: ['orderParameterName' => 'order'])]
class BlogComment
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['blog_comment:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: BlogPost::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['blog_comment:read', 'blog_comment:write'])]
    private ?BlogPost $blogPost = null;

    #[ORM\Column(length: 100)]
    #[Groups(['blog_comment:read', 'blog_comment:write'])]
    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: 100)]
    private ?string $authorName = null;

    #[ORM\Column(length: 180)]
    #[Groups(['blog_comment:write'])]
    #[Assert\NotBlank(message: "L'email est obligatoire.")]
    #[Assert\Email(message: "L'email n'est pas valide.")]
    private ?string $authorEmail = null;

    #[ORM\Column(type: 'text')]
    #[Groups(['blog_comment:read', 'blog_comment:write'])]
    #[Assert\NotBlank(message: 'Le commentaire est obligatoire.')]
    #[Assert\Length(
        min: 10,
        max: 1000,
        minMessage: 'Le commentaire doit faire au moins {{ limit }} caractères.',
        maxMessage: 'Le commentaire ne peut pas dépasser {{ limit }} caractères.'
    )]
    private ?string $content = null;

    #[ORM\Column(type: 'smallint')]
    #[Groups(['blog_comment:read', 'blog_comment:write'])]
    #[Assert\NotNull(message: 'La note est obligatoire.')]
    #[Assert\Range(min: 1, max: 5, notInRangeMessage: 'La note doit être entre 1 et 5.')]
    private ?int $rating = null;

    #[ORM\Column(type: 'boolean')]
    #[Groups(['blog_comment:read'])]
    private bool $isApproved = false;

    #[ORM\Column]
    #[Groups(['blog_comment:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid { return $this->id; }

    public function getBlogPost(): ?BlogPost { return $this->blogPost; }
    public function setBlogPost(?BlogPost $blogPost): static { $this->blogPost = $blogPost; return $this; }

    public function getAuthorName(): ?string { return $this->authorName; }
    public function setAuthorName(string $authorName): static { $this->authorName = $authorName; return $this; }

    public function getAuthorEmail(): ?string { return $this->authorEmail; }
    public function setAuthorEmail(string $authorEmail): static { $this->authorEmail = $authorEmail; return $this; }

    public function getContent(): ?string { return $this->content; }
    public function setContent(string $content): static { $this->content = $content; return $this; }

    public function getRating(): ?int { return $this->rating; }
    public function setRating(?int $rating): static { $this->rating = $rating; return $this; }

    public function isApproved(): bool { return $this->isApproved; }
    public function setIsApproved(bool $isApproved): static { $this->isApproved = $isApproved; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
