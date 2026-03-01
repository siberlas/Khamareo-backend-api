<?php

namespace App\Catalog\Entity;

use App\Catalog\Repository\ProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use ApiPlatform\Metadata\ApiResource;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Serializer\Annotation\MaxDepth;
use App\Catalog\Filter\CategoryOrChildrenFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Translatable\Translatable;
use App\Media\Entity\ProductMedia;
use App\Shared\Entity\Currency;
use App\Media\Entity\Media;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ApiResource(
    normalizationContext: ['groups' => ['product:read']],
    denormalizationContext: ['groups' => ['product:write']],
     operations: [
        new GetCollection(security: "is_granted('PUBLIC_ACCESS')"),
        new Get(security: "is_granted('PUBLIC_ACCESS')"),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Patch(security: "is_granted('ROLE_ADMIN')"),
        new Delete(security: "is_granted('ROLE_ADMIN')")
     ],
)]
#[ApiFilter(CategoryOrChildrenFilter::class)]
#[ApiFilter(OrderFilter::class, properties: ['price', 'createdAt', 'rating'], arguments: ['orderParameterName' => 'order'])]
#[ApiFilter(SearchFilter::class, properties: [
    'name' => 'ipartial',
    'description' => 'ipartial',
])]
class Product
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['product:read'])]
    #[ApiProperty(identifier: false)]
    private ?Uuid $id = null;

    #[Groups(['product:read', 'product:write','order:read','cart:read','favorite:read'])]
    #[ORM\Column(length: 255)]
    #[Gedmo\Translatable]
    private ?string $name = null;

    #[Groups(['product:read', 'product:write'])]
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Gedmo\Translatable]
    private ?string $description = null;

    #[Groups(['product:read', 'product:write','order:read','favorite:read'])]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?float $price = null;

    #[Groups(['product:read', 'product:write','cart:read'])]
    #[ORM\Column(nullable: true)]
    private ?float $weight = null;

    #[Groups(['product:read', 'product:write'])]
    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[Groups(['product:read', 'product:write','cart:read'])]
    #[ORM\Column]
    private ?int $stock = 0;

    #[Groups(['product:read', 'product:write','cart:read','favorite:read'])]
    #[ORM\Column(length: 255)]
    private ?string $imageUrl = null;

    #[Groups(['product:read', 'product:write','favorite:read'])]
    #[ORM\Column(length: 255, unique: true)]
    #[ApiProperty(identifier: true)]
    private ?string $slug = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[Groups(['product:read','favorite:read'])]
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
    #[ORM\ManyToOne(targetEntity: Badge::class)]
    #[ORM\JoinColumn(name: 'badge_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Badge $badge = null;

    #[Groups(['product:read', 'product:write'])]
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $benefits = null;

    #[Groups(['product:read', 'product:write'])]
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Gedmo\Translatable]
    private ?string $ingredients = null;

    #[Groups(['product:read', 'product:write'])]
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Gedmo\Translatable]
    private ?string $usage = null;

    #[Groups(['product:read', 'product:write'])]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?float $originalPrice = null;

    #[Groups(['product:read'])]
    private ?Currency $currency = null;

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

    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductPrice::class, cascade: ['persist', 'remove'])]
    private Collection $prices;
    #[Gedmo\Locale]
    private string $locale = 'fr';

     /**
     * @var Collection<int, ProductMedia>
     */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductMedia::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['displayOrder' => 'ASC'])]
    #[Groups(['product:read'])]
    private Collection $productMedias;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->relatedProducts = new ArrayCollection();
        $this->reviews = new ArrayCollection();
        $this->prices = new ArrayCollection();
        $this->productMedias = new ArrayCollection();

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

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getPrice(): ?float { return $this->price; }
    public function setPrice(float $price): static { $this->price = $price; return $this; }

    public function getWeight(): ?float { return $this->weight; }
    public function setWeight(?float $weight): static { $this->weight = $weight; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    #[ORM\PrePersist]
    public function setCreatedAt(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getStock(): ?int { return $this->stock; }
    public function setStock(int $stock): static { $this->stock = $stock; return $this; }

    public function getImageUrl(): ?string { return $this->imageUrl; }
    #[Groups(['product:read'])] public function getImage(): ?string { return $this->imageUrl; }
    public function setImageUrl(?string $imageUrl): static { $this->imageUrl = $imageUrl; return $this; }

    public function getSlug(): ?string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PreUpdate]
    public function setUpdatedAt(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function getCategory(): ?Category { return $this->category; }
    public function setCategory(?Category $category): static { $this->category = $category; return $this; }

    public function getReviewsCount(): ?int { return $this->reviewsCount; }
    public function setReviewsCount(?int $reviewsCount): self { $this->reviewsCount = $reviewsCount; return $this; }

    public function getReviews(): Collection { return $this->reviews; }
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
        if ($this->reviews->removeElement($review) && $review->getProduct() === $this) {
            $review->setProduct(null);
        }
        return $this;
    }

    public function getRating(): ?float { return $this->rating; }
    public function setRating(?float $rating): static { $this->rating = $rating; return $this; }

    public function getBadge(): ?Badge { return $this->badge; }
    public function setBadge(?Badge $badge): static { $this->badge = $badge; return $this; }

    public function getBenefits(): ?array { return $this->benefits; }
    public function setBenefits(?array $benefits): static { $this->benefits = $benefits; return $this; }

    public function getIngredients(): ?string { return $this->ingredients; }
    public function setIngredients(?string $ingredients): static { $this->ingredients = $ingredients; return $this; }

    public function getUsage(): ?string { return $this->usage; }
    public function setUsage(?string $usage): static { $this->usage = $usage; return $this; }

    public function getOriginalPrice(): ?float { return $this->originalPrice; }
    public function setOriginalPrice(?float $originalPrice): static { $this->originalPrice = $originalPrice; return $this; }

    public function getImages(): ?array { return $this->images; }
    public function setImages(?array $images): static { $this->images = $images; return $this; }

    public function getRelatedProducts(): Collection
    {
        return new ArrayCollection($this->relatedProducts->slice(0, 3));
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

    public function setTranslatableLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function getTranslatableLocale(): string
    {
        return $this->locale;
    }

    /**
     * @return Collection<int, ProductPrice>
     */
    public function getPrices(): Collection
    {
        return $this->prices;
    }

    public function addPrice(ProductPrice $price): self
    {
        if (!$this->prices->contains($price)) {
            $this->prices[] = $price;
            $price->setProduct($this);
        }
        return $this;
    }

    public function removePrice(ProductPrice $price): self
    {
        if ($this->prices->removeElement($price)) {
            if ($price->getProduct() === $this) {
                $price->setProduct(null);
            }
        }
        return $this;
    }

    /**
     * Get price for a specific currency
     */
    public function getPriceForCurrency(string $currencyCode): ?ProductPrice
    {
        foreach ($this->prices as $price) {
            if ($price->getCurrencyCode() === $currencyCode) {
                return $price;
            }
        }
        return null;
    }
    // Nouveau : Getter/Setter pour currency (champ transitoire)
    public function getCurrency(): ?Currency
    {
        return $this->currency;
    }

    public function setCurrency(?Currency $currency): self
    {
        $this->currency = $currency;
        return $this;
    }

    /**
     * @return Collection<int, ProductMedia>
     */
    public function getProductMedias(): Collection
    {
        return $this->productMedias;
    }

    public function addProductMedia(ProductMedia $productMedia): self
    {
        if (!$this->productMedias->contains($productMedia)) {
            $this->productMedias->add($productMedia);
            $productMedia->setProduct($this);
        }
        return $this;
    }

    public function removeProductMedia(ProductMedia $productMedia): self
    {
        if ($this->productMedias->removeElement($productMedia)) {
            if ($productMedia->getProduct() === $this) {
                $productMedia->setProduct(null);
            }
        }
        return $this;
    }

    /**
     * Récupère l'image principale du produit
     */
    #[Groups(['product:read'])]
    public function getPrimaryMedia(): ?Media
    {
        foreach ($this->productMedias as $productMedia) {
            if ($productMedia->isPrimary()) {
                return $productMedia->getMedia();
            }
        }
        // Fallback sur la première image
        return $this->productMedias->first() ? $this->productMedias->first()->getMedia() : null;
    }

    /**
     * Récupère toutes les images sous forme de tableau
     */
    #[Groups(['product:read'])]
    public function getMediaGallery(): array
    {
        return $this->productMedias->map(fn(ProductMedia $pm) => $pm->getMedia())->toArray();
    }
}
