<?php

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use ApiPlatform\Metadata\ApiResource;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Serializer\Annotation\MaxDepth;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ApiResource(
    normalizationContext: ['groups' => ['product:read']],
    denormalizationContext: ['groups' => ['product:write']],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'name' => 'ipartial',
    'category.slug' => 'iexact', // ou 'partial' si tu veux recherche textuelle
])]
#[ApiFilter(OrderFilter::class, properties: ['price', 'createdAt','rating'], arguments: ['orderParameterName' => 'order'])]
class Product
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['product:read'])]
    #[ApiProperty(identifier: false)]
    private ?Uuid $id = null;


    #[Groups(['product:read', 'product:write'])]
    #[ORM\Column(length: 255)]
    private ?string $name = null;


    #[Groups(['product:read', 'product:write'])]
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;


    #[Groups(['product:read', 'product:write'])]
    #[ORM\Column]
    private ?float $price = null;


    #[Groups(['product:read', 'product:write'])]
    #[ORM\Column(nullable: true)]
    private ?float $weight = null;


    #[Groups(['product:read', 'product:write'])]
    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[Groups(['product:read', 'product:write'])]
    #[ORM\Column]
    private ?int $stock = 0;

    #[Groups(['product:read', 'product:write'])]
    #[ORM\Column(length: 255)]
    private ?string $imageUrl = null; // plusieurs images

    #[Groups(['product:read', 'product:write'])]
    #[ORM\Column(length: 255, unique: true)]
    #[ApiProperty(identifier: true)]
    private ?string $slug = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;
    
    #[Groups(['product:read'])]
    #[ORM\ManyToOne(inversedBy: 'products')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Category $category = null;

    #[Groups(['product:read', 'product:write'])]
    #[ORM\Column(nullable: true)]
    private ?int $reviewsCount = null;
    
    #[Groups(['product:read', 'product:write'])]
    #[ORM\Column(nullable: true)]
    private ?float $rating = null;

    #[Groups(['product:read', 'product:write'])]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $badge = null;

    #[Groups(['product:read', 'product:write'])]
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $benefits = null;

    #[Groups(['product:read', 'product:write'])]
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $ingredients = null;
    
    #[Groups(['product:read', 'product:write'])]
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $usage = null;
    
    #[Groups(['product:read', 'product:write'])]
    #[ORM\Column(nullable: true)]
    private ?float $originalPrice = null;
    
    #[Groups(['product:read', 'product:write'])]
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $images = null;

    #[ORM\ManyToMany(targetEntity: self::class, fetch: "EXTRA_LAZY")]
    #[ORM\JoinTable(name: "product_related")]
    #[Groups(['product:read', 'product:write'])]
    #[MaxDepth(1)]
    private Collection $relatedProducts;

    #[ORM\OneToMany(mappedBy: 'product', targetEntity: Review::class, cascade: ['persist', 'remove'])]
    #[Groups(['product:read'])]
    private Collection $reviews;


    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->relatedProducts = new ArrayCollection();
        $this->reviews = new ArrayCollection();

    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    #[Groups(['product:read'])]
    public function getCategoryName(): ?string
    {
        return $this->category?->getName();
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function setPrice(float $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getWeight(): ?float
    {
        return $this->weight;
    }

    public function setWeight(?float $weight): static
    {
        $this->weight = $weight;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[ORM\PrePersist]
    public function setCreatedAt(): void
    {
        $this->createdAt =  new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getStock(): ?int
    {
        return $this->stock;
    }

    public function setStock(int $stock): static
    {
        $this->stock = $stock;

        return $this;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }
    
    #[Groups(['product:read'])]
    public function getImage(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): static
    {
        $this->imageUrl = $imageUrl;

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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function setUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getReviewsCount(): ?int
    {
        return $this->reviewsCount;
    }

    public function setReviewsCount(?int $reviewsCount): self
    {
        $this->reviewsCount = $reviewsCount;
        return $this;
    }

    public function getReviews(): Collection
    {
        return $this->reviews;
    }

    public function addReview(Review $review): self
    {
        if (!$this->reviews->contains($review)) {
            $this->reviews->add($review);
            $review->setProduct($this);
        }

        return $this;
    }

    public function removeReview(Review $review): self
    {
        if ($this->reviews->removeElement($review)) {
            if ($review->getProduct() === $this) {
                $review->setProduct(null);
            }
        }

        return $this;
    }

    public function getRating(): ?float
    {
        return $this->rating;
    }

    public function setRating(?float $rating): static
    {
        $this->rating = $rating;

        return $this;
    }

    public function getBadge(): ?string
    {
        return $this->badge;
    }

    public function setBadge(?string $badge): static
    {
        $this->badge = $badge;

        return $this;
    }

    public function getBenefits(): ?array
    {
        return $this->benefits;
    }

    public function setBenefits(?array $benefits): static
    {
        $this->benefits = $benefits;

        return $this;
    }

    public function getIngredients(): ?string
    {
        return $this->ingredients;
    }

    public function setIngredients(?string $ingredients): static
    {
        $this->ingredients = $ingredients;

        return $this;
    }

    public function getUsage(): ?string
    {
        return $this->usage;
    }

    public function setUsage(?string $usage): static
    {
        $this->usage = $usage;

        return $this;
    }

    public function getOriginalPrice(): ?float
    {
        return $this->originalPrice;
    }

    public function setOriginalPrice(?float $originalPrice): static
    {
        $this->originalPrice = $originalPrice;

        return $this;
    }

    public function getImages(): ?array
    {
        return $this->images;
    }

    public function setImages(?array $images): static
    {
        $this->images = $images;

        return $this;
    }

    public function getRelatedProducts(): Collection
    {
         return new ArrayCollection(
            $this->relatedProducts->slice(0, 3)
        );
    }

    public function addRelatedProduct(Product $product): self
    {
        if (!$this->relatedProducts->contains($product)) {
            $this->relatedProducts->add($product);
        }
        return $this;
    }

    public function removeRelatedProduct(Product $product): self
    {
        $this->relatedProducts->removeElement($product);
        return $this;
    }
}
