<?php

namespace App\Catalog\Entity;

use App\Catalog\Repository\CategoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use ApiPlatform\Metadata\ApiResource;
use Symfony\Component\Serializer\Annotation\Groups;
use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Uid\Uuid;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Translatable\Translatable;
use App\Media\Entity\CategoryMedia;
use App\Media\Entity\Media;

#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ApiResource(
    normalizationContext: ['groups' => ['category:read']],
    denormalizationContext: ['groups' => ['category:write']],
    operations: [
        new GetCollection(security: "is_granted('PUBLIC_ACCESS')"),
        new Get(security: "is_granted('PUBLIC_ACCESS')"),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Patch(security: "is_granted('ROLE_ADMIN')"),
        new Delete(security: "is_granted('ROLE_ADMIN')")
    ]
)]
class Category
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['category:read', 'product:read'])]
    #[ApiProperty(identifier: false)]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Groups(['category:read', 'category:write', 'product:read'])]
    #[ApiProperty(identifier: true)]
    private ?string $slug = null;

    #[ORM\Column(length: 255)]
    #[Groups(['category:read', 'category:write', 'product:read'])]
    #[Gedmo\Translatable]
    private ?string $name = null;

    #[Groups(['category:read', 'category:write'])]
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Gedmo\Translatable]
    private ?string $description = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['category:read', 'category:write'])]
    private bool $isEnabled = true;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Groups(['category:read', 'category:write'])]
    private int $displayOrder = 0;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[Groups(['category:read', 'category:write'])]
    private ?self $parent = null;

    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class, cascade: ['persist', 'remove'])]
    #[Groups(['category:read'])]
    private Collection $children;

    #[ORM\OneToMany(mappedBy: 'category', targetEntity: Product::class, cascade: ['persist', 'remove'])]
    #[Groups(['category:read'])]
    private Collection $products;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['category:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['category:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    #[Gedmo\Locale]
    private string $locale = 'fr';

     /**
     * @var Collection<int, CategoryMedia>
     */
    #[ORM\OneToMany(mappedBy: 'category', targetEntity: CategoryMedia::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['category:read'])]
    private Collection $categoryMedias;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->children = new ArrayCollection();
        $this->products = new ArrayCollection();
        $this->categoryMedias = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;
        return $this;
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

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function isEnabled(): bool { return $this->isEnabled; }
    public function setIsEnabled(bool $isEnabled): static { $this->isEnabled = $isEnabled; return $this; }

    public function getDisplayOrder(): int { return $this->displayOrder; }
    public function setDisplayOrder(int $displayOrder): static { $this->displayOrder = $displayOrder; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }

    public function getProductsCount(): int
    {
        return $this->products->count();
    }

    public function getTotalProductsCount(): int
    {
        $count = $this->products->count();
        foreach ($this->children as $child) {
            $count += $child->getTotalProductsCount();
        }
        return $count;
    }

    public function hasChildren(): bool
    {
        return !$this->children->isEmpty();
    }

    public function getLevel(): int
    {
        $level = 0;
        $current = $this->parent;
        while ($current !== null) {
            $level++;
            $current = $current->getParent();
        }
        return $level;
    }

    public function getPath(): array
    {
        $path = [];
        $current = $this;
        while ($current !== null) {
            array_unshift($path, $current->getName());
            $current = $current->getParent();
        }
        return $path;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        $this->parent = $parent;
        return $this;
    }

    /**
     * @return Collection<int, Category>
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function addChild(Category $child): self
    {
        if (!$this->children->contains($child)) {
            $this->children->add($child);
            $child->setParent($this);
        }
        return $this;
    }

    public function removeChild(Category $child): self
    {
        if ($this->children->removeElement($child)) {
            if ($child->getParent() === $this) {
                $child->setParent(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, Product>
     */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function addProduct(Product $product): self
    {
        if (!$this->products->contains($product)) {
            $this->products->add($product);
            $product->setCategory($this);
        }
        return $this;
    }

    public function removeProduct(Product $product): self
    {
        if ($this->products->removeElement($product)) {
            if ($product->getCategory() === $this) {
                $product->setCategory(null);
            }
        }
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
     * @return Collection<int, CategoryMedia>
     */
    public function getCategoryMedias(): Collection
    {
        return $this->categoryMedias;
    }

    public function addCategoryMedia(CategoryMedia $categoryMedia): self
    {
        if (!$this->categoryMedias->contains($categoryMedia)) {
            $this->categoryMedias->add($categoryMedia);
            $categoryMedia->setCategory($this);
        }
        return $this;
    }

    public function removeCategoryMedia(CategoryMedia $categoryMedia): self
    {
        if ($this->categoryMedias->removeElement($categoryMedia)) {
            if ($categoryMedia->getCategory() === $this) {
                $categoryMedia->setCategory(null);
            }
        }
        return $this;
    }

    /**
     * Récupère l'image principale de la catégorie
     */
    #[Groups(['category:read'])]
    public function getMainMedia(): ?Media
    {
        foreach ($this->categoryMedias as $categoryMedia) {
            if ($categoryMedia->getMediaUsage() === 'main') {
                return $categoryMedia->getMedia();
            }
        }
        return $this->categoryMedias->first() ? $this->categoryMedias->first()->getMedia() : null;
    }

    /**
     * Récupère l'image bannière
     */
    #[Groups(['category:read'])]
    public function getBannerMedia(): ?Media
    {
        foreach ($this->categoryMedias as $categoryMedia) {
            if ($categoryMedia->getMediaUsage() === 'banner') {
                return $categoryMedia->getMedia();
            }
        }
        return null;
    }
}
