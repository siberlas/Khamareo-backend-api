<?php

namespace App\Marketing\Entity;

use App\Marketing\Repository\PromoCodeRepository;
use Doctrine\ORM\Mapping as ORM;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Patch;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: PromoCodeRepository::class)]
#[Assert\Callback([self::class, 'validateDiscount'])]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('ROLE_ADMIN')"),
        new Get(security: "is_granted('ROLE_ADMIN')"),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Patch(security: "is_granted('ROLE_ADMIN')")
    ],
    normalizationContext: ['groups' => ['promo:read']],
    denormalizationContext: ['groups' => ['promo:write']]
)]
class PromoCode
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['promo:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: 50, unique: true)]
    #[Groups(['promo:read', 'promo:write'])]
    private ?string $code = null;

    #[ORM\Column(type: 'string', length: 50)]
    #[Groups(['promo:read', 'promo:write'])]
    #[Assert\Choice(choices: ['newsletter', 'first_order', 'registration', 'manual'])]
    private ?string $type = null;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    #[Groups(['promo:read', 'promo:write'])]
    #[Assert\Range(min: 0, max: 100)]
    private ?string $discountPercentage = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['promo:read', 'promo:write'])]
    private ?string $discountAmount = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['promo:read', 'promo:write'])]
    private ?string $email = null;

    #[ORM\Column]
    #[Groups(['promo:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['promo:read'])]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column]
    #[Groups(['promo:read', 'promo:write', 'newsletter:read'])]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column]
    #[Groups(['promo:read', 'promo:write'])]
    private bool $isUsed = false;

    #[ORM\Column]
    #[Groups(['promo:read', 'promo:write'])]
    private bool $isActive = true;

    #[ORM\Column(nullable: true)]
    #[Groups(['promo:read', 'promo:write'])]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['promo:read', 'promo:write'])]
    private ?string $minOrderAmount = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['promo:read', 'promo:write'])]
    private ?int $maxUses = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['promo:read', 'promo:write'])]
    private ?int $maxUsesPerEmail = null;

    #[ORM\Column(length: 20, options: ['default' => 'all'])]
    #[Groups(['promo:read', 'promo:write'])]
    private string $eligibleCustomer = 'all';

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['promo:read', 'promo:write'])]
    private bool $stackable = false;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['promo:read', 'promo:write'])]
    private bool $firstOrderOnly = false;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['promo:read', 'promo:write'])]
    private ?string $maxDiscountAmount = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['promo:read', 'promo:write'])]
    private ?int $usageWindowDays = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['promo:read', 'promo:write'])]
    private bool $autoApply = false;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['promo:read', 'promo:write'])]
    private ?array $allowedCountries = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['promo:read', 'promo:write'])]
    private ?array $allowedLocales = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['promo:read', 'promo:write'])]
    private ?array $allowedChannels = null;

    #[ORM\OneToMany(mappedBy: 'promoCode', targetEntity: PromoCodeRedemption::class, cascade: ['remove'])]
    private Collection $redemptions;

    #[ORM\OneToMany(mappedBy: 'promoCode', targetEntity: PromoCodeRecipient::class, cascade: ['remove'])]
    private Collection $recipients;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->redemptions = new ArrayCollection();
        $this->recipients = new ArrayCollection();
    }

    /** @return Collection<int, PromoCodeRedemption> */
    public function getRedemptions(): Collection { return $this->redemptions; }

    /** @return Collection<int, PromoCodeRecipient> */
    public function getRecipients(): Collection { return $this->recipients; }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // Getters et Setters

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getDiscountPercentage(): ?string
    {
        return $this->discountPercentage;
    }

    public function setDiscountPercentage(?string $discountPercentage): static
    {
        $this->discountPercentage = $discountPercentage;
        return $this;
    }

    public function getDiscountAmount(): ?string
    {
        return $this->discountAmount;
    }

    public function setDiscountAmount(?string $discountAmount): static
    {
        $this->discountAmount = $discountAmount;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
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

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function setUsedAt(?\DateTimeImmutable $usedAt): static
    {
        $this->usedAt = $usedAt;
        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function isUsed(): bool
    {
        return $this->isUsed;
    }

    public function setIsUsed(bool $isUsed): static
    {
        $this->isUsed = $isUsed;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getStartsAt(): ?\DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(?\DateTimeImmutable $startsAt): static { $this->startsAt = $startsAt; return $this; }

    public function getMinOrderAmount(): ?string { return $this->minOrderAmount; }
    public function setMinOrderAmount(?string $minOrderAmount): static { $this->minOrderAmount = $minOrderAmount; return $this; }

    public function getMaxUses(): ?int { return $this->maxUses; }
    public function setMaxUses(?int $maxUses): static { $this->maxUses = $maxUses; return $this; }

    public function getMaxUsesPerEmail(): ?int { return $this->maxUsesPerEmail; }
    public function setMaxUsesPerEmail(?int $maxUsesPerEmail): static { $this->maxUsesPerEmail = $maxUsesPerEmail; return $this; }

    public function getEligibleCustomer(): string { return $this->eligibleCustomer; }
    public function setEligibleCustomer(string $eligibleCustomer): static { $this->eligibleCustomer = $eligibleCustomer; return $this; }

    public function isStackable(): bool { return $this->stackable; }
    public function setStackable(bool $stackable): static { $this->stackable = $stackable; return $this; }

    public function isFirstOrderOnly(): bool { return $this->firstOrderOnly; }
    public function setFirstOrderOnly(bool $firstOrderOnly): static { $this->firstOrderOnly = $firstOrderOnly; return $this; }

    public function getMaxDiscountAmount(): ?string { return $this->maxDiscountAmount; }
    public function setMaxDiscountAmount(?string $maxDiscountAmount): static { $this->maxDiscountAmount = $maxDiscountAmount; return $this; }

    public function getUsageWindowDays(): ?int { return $this->usageWindowDays; }
    public function setUsageWindowDays(?int $usageWindowDays): static { $this->usageWindowDays = $usageWindowDays; return $this; }

    public function isAutoApply(): bool { return $this->autoApply; }
    public function setAutoApply(bool $autoApply): static { $this->autoApply = $autoApply; return $this; }

    public function getAllowedCountries(): ?array { return $this->allowedCountries; }
    public function setAllowedCountries(?array $allowedCountries): static { $this->allowedCountries = $allowedCountries; return $this; }

    public function getAllowedLocales(): ?array { return $this->allowedLocales; }
    public function setAllowedLocales(?array $allowedLocales): static { $this->allowedLocales = $allowedLocales; return $this; }

    public function getAllowedChannels(): ?array { return $this->allowedChannels; }
    public function setAllowedChannels(?array $allowedChannels): static { $this->allowedChannels = $allowedChannels; return $this; }

    /**
     * Un code est "à usage unique" s'il a une restriction email directe OU si maxUses = 1.
     * Dans ce cas, le flag isUsed bloque les réutilisations.
     * Pour les codes multi-usages (maxUses > 1 ou null), on comptabilise via PromoCodeRedemption.
     */
    public function isSingleInstance(): bool
    {
        return $this->maxUses === 1 || (!empty($this->email) && $this->email !== '');
    }

    public function isValid(): bool
    {
        if (!$this->isActive) return false;
        if ($this->expiresAt !== null && $this->expiresAt <= new \DateTimeImmutable()) return false;
        // Pour les codes à usage unique, le flag isUsed bloque la réutilisation
        if ($this->isSingleInstance() && $this->isUsed) return false;
        return true;
    }

    public static function validateDiscount(self $promo, \Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        $hasPercentage = $promo->getDiscountPercentage() !== null && (float) $promo->getDiscountPercentage() > 0;
        $hasAmount     = $promo->getDiscountAmount()    !== null && (float) $promo->getDiscountAmount()    > 0;

        if (!$hasPercentage && !$hasAmount) {
            $context->buildViolation('Vous devez définir une remise (pourcentage ou montant fixe).')
                ->atPath('discountPercentage')
                ->addViolation();
        }

        if ($hasPercentage && $hasAmount) {
            $context->buildViolation('Vous ne pouvez pas cumuler un pourcentage et un montant fixe.')
                ->atPath('discountPercentage')
                ->addViolation();
        }
    }
}