<?php

namespace App\Entity;

use App\Repository\NewsletterSubscriberRepository;
use Doctrine\ORM\Mapping as ORM;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Delete;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Annotation\Groups;
use App\State\NewsletterSubscriberProcessor;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: NewsletterSubscriberRepository::class)]
#[UniqueEntity(
    fields: ['email'],
    message: 'Cet email est déjà inscrit à la newsletter.'
)]
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_ADMIN')"
        ),
        new Get(
            security: "is_granted('ROLE_ADMIN')"
        ),
        new Post(
            security: "true",
            validationContext: ['groups' => ['newsletter:create']],
            processor: NewsletterSubscriberProcessor::class,
            normalizationContext: ['groups' => ['newsletter:read']]
        ),
        new Delete(
            security: "is_granted('ROLE_ADMIN')"
        )
    ],
    normalizationContext: ['groups' => ['newsletter:read']],
    denormalizationContext: ['groups' => ['newsletter:create']]
)]
class NewsletterSubscriber
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['newsletter:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Assert\NotBlank(groups: ['newsletter:create'])]
    #[Assert\Email(
        message: 'L\'adresse email {{ value }} n\'est pas valide.',
        groups: ['newsletter:create']
    )]
    #[Groups(['newsletter:read', 'newsletter:create'])]
    private ?string $email = null;

    #[ORM\Column]
    #[Groups(['newsletter:read'])]
    private ?\DateTimeImmutable $subscribedAt = null;

    // Propriété transiente pour le code promo (non stockée en BDD)
    #[Groups(['newsletter:read'])]
    private ?string $promoCode = null;

    #[Groups(['newsletter:read'])]
    private ?float $promoDiscountPercentage = null;

    #[Groups(['newsletter:read'])]
    private ?float $promoDiscountAmount = null;

    #[Groups(['newsletter:read'])]
    private ?\DateTimeImmutable $promoExpiresAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
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

    public function getSubscribedAt(): ?\DateTimeImmutable
    {
        return $this->subscribedAt;
    }

    public function setSubscribedAt(\DateTimeImmutable $subscribedAt): static
    {
        $this->subscribedAt = $subscribedAt;

        return $this;
    }

      /**
     * Get the value of promoCode
     */ 
    public function getPromoCode(): ?string
    {
        return $this->promoCode;
    }

    /**
     * Set the value of promoCode
     *
     * @return  self
     */ 
    public function setPromoCode(string $promoCode): static
    {
        $this->promoCode = $promoCode;

        return $this;
    }

    public function getPromoDiscountPercentage(): ?float
    {
        return $this->promoDiscountPercentage;
    }

    public function setPromoDiscountPercentage(?float $promoDiscountPercentage): static
    {
        $this->promoDiscountPercentage = $promoDiscountPercentage;
        return $this;
    }

    public function getPromoDiscountAmount(): ?float
    {
        return $this->promoDiscountAmount;
    }

    public function setPromoDiscountAmount(?float $promoDiscountAmount): static
    {
        $this->promoDiscountAmount = $promoDiscountAmount;
        return $this;
    }

    public function getPromoExpiresAt(): ?\DateTimeImmutable
    {
        return $this->promoExpiresAt;
    }

    public function setPromoExpiresAt(?\DateTimeImmutable $promoExpiresAt): static
    {
        $this->promoExpiresAt = $promoExpiresAt;
        return $this;
    }



    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->subscribedAt = new \DateTimeImmutable();
    }



  
}