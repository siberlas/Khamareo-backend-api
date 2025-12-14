<?php

namespace App\Entity;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Annotation\Groups;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\GetCollection;

use App\State\CurrentUserProvider;
use ApiPlatform\Metadata\ApiProperty;
use App\Entity\Address;
use App\Repository\UserRepository;
use App\State\UserPasswordHasher;
use App\Dto\ChangePasswordRequest;
use App\State\ChangePasswordProcessor;

#[UniqueEntity(
    fields: ['email'],
    message: "Un compte existe déjà avec cette adresse e-mail.",
    groups: ['registration']
)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ApiResource(
    normalizationContext: ['groups' => ['user:read']],
    denormalizationContext: ['groups' => ['user:write']],
    operations: [
        new GetCollection(security: "is_granted('ROLE_ADMIN')"),
        new Get(
            name: 'get_current_user',
            uriTemplate: '/users/me',
            security: "is_granted('ROLE_USER')",
            provider: CurrentUserProvider::class
        ),
         new Get(
            name: 'get_guest_user',
            uriTemplate: '/users/guest',
            security: "is_granted('ROLE_ADMIN')",
        ),
        new Get(security: "is_granted('ROLE_ADMIN')"),
        new Post(
            processor: UserPasswordHasher::class,
            security: "is_granted('PUBLIC_ACCESS')",
            validationContext: ['groups' => ['registration']]
        ),
        new Post(
            uriTemplate: '/users/change-password',
            processor: ChangePasswordProcessor::class,
            input: ChangePasswordRequest::class,
            security: "is_granted('ROLE_USER')",
            denormalizationContext: ['groups' => ['change-password']],
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and object == user",
            processor: UserPasswordHasher::class),
        new Delete(security: "is_granted('ROLE_ADMIN')")
    ]
)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['user:read'])]
    #[ApiProperty(identifier: true)]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(groups:['registration'],message: "L'adresse e-mail est obligatoire.")]
    #[Assert\Email(groups:['registration'],message: "L'adresse e-mail '{{ value }}' n'est pas valide.")]
    #[Groups(['user:read', 'user:write'])]
    private ?string $email = null;

    #[ORM\Column(length: 255)]
    private ?string $password = null;

    #[Assert\NotBlank(groups: ['registration'])]
    #[Groups(['user:write'])]
    private ?string $plainPassword = null;

    #[ORM\Column]
    #[Groups(['user:read'])]
    private array $roles = [];

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(groups: ['registration'], message: "Le prénom est obligatoire.")]
    #[Groups(['user:read', 'user:write'])]
    private ?string $firstName = null;
    
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(groups: ['registration'], message: "Le nom est obligatoire.")]
    #[Groups(['user:read', 'user:write'])]
    private ?string $lastName = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['user:read', 'user:write'])]
    private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['user:read', 'user:write'])]
    private ?string $address = null;

    #[ORM\Column]
    #[Groups(['user:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['user:read'])]
    private ?\DateTimeImmutable $updatedAt = null;


    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: Address::class, cascade: ['persist', 'remove'])]
    #[Groups(['user:read'])]
    private Collection $addresses;

    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: Cart::class)]
    private Collection $carts;

        // src/Entity/User.php
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $stripeCustomerId = null;

    /**
     * @var Collection<int, BlogPost>
     */
    #[ORM\OneToMany(targetEntity: BlogPost::class, mappedBy: 'author')]
    private Collection $blogPosts;

    #[ORM\Column(type: 'boolean')]
    #[Groups(['user:read', 'user:write'])]
    #[Assert\IsTrue(groups:['registration'],message: "Vous devez accepter les conditions générales pour créer un compte.")]
    private bool $acceptTerms = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['user:read', 'user:write'])]
    private bool $newsletter = false;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $confirmationToken = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isVerified = false;

    #[ORM\Column(nullable: true)]
    private ?string $resetPasswordToken = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $resetPasswordRequestedAt = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['user:read', 'user:write'])]
    private bool $isGuest = false;

    #[ORM\Column(nullable: true)]
    #[Groups(['user:read'])]
    private ?\DateTimeImmutable $guestExpiresAt = null;

    #[ORM\Column(type: 'boolean')]
    #[Groups(['user:read', 'user:write'])]
    private bool $hasAcceptedGuestTerms = false;

    // Propriétés transientes pour le code promo (non stockées en BDD)
    #[Groups(['user:read'])]
    private ?string $promoCode = null;

    #[Groups(['user:read'])]
    private ?float $promoDiscountPercentage = null;

    #[Groups(['user:read'])]
    private ?float $promoDiscountAmount = null;

    #[Groups(['user:read'])]
    private ?\DateTimeImmutable $promoExpiresAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        
        if ($this->createdAt === null) {
            $this->createdAt = $now;
        }
        
        // Attribuer le rôle par défaut
        if (empty($this->roles)) {
            $this->roles = ['ROLE_USER'];
        }
        
        // ✅ Si c'est un invité, définir la date d'expiration
        if ($this->isGuest && $this->guestExpiresAt === null) {
            $this->guestExpiresAt = $now->modify('+30 days');
        }
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

   
    public function __construct()
    {
        $this->blogPosts = new ArrayCollection();
        $this->addresses = new ArrayCollection();
        $this->carts = new ArrayCollection();
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

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

     public function getPlainPassword(): ?string
     {
        return $this->plainPassword;
     }

    public function setPlainPassword(?string $plainPassword): self
    {
        $this->plainPassword = $plainPassword;

        return $this;
    }

   

    public function getRoles(): array
    {
        $roles = $this->roles;

        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

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

    // ✅ NOUVEAU
    public function getGuestExpiresAt(): ?\DateTimeImmutable
    {
        return $this->guestExpiresAt;
    }

    public function setGuestExpiresAt(?\DateTimeImmutable $guestExpiresAt): static
    {
        $this->guestExpiresAt = $guestExpiresAt;
        return $this;
    }

    // ✅ NOUVEAU
    public function hasAcceptedGuestTerms(): bool
    {
        return $this->hasAcceptedGuestTerms;
    }

    public function setHasAcceptedGuestTerms(bool $hasAcceptedGuestTerms): static
    {
        $this->hasAcceptedGuestTerms = $hasAcceptedGuestTerms;
        return $this;
    }

    // ✅ NOUVEAU : Vérifier si l'invité a expiré
    public function isGuestExpired(): bool
    {
        if (!$this->isGuest || !$this->guestExpiresAt) {
            return false;
        }
        
        return $this->guestExpiresAt < new \DateTimeImmutable();
    }

    /**
     * @return Collection<int, BlogPost>
     */
    public function getBlogPosts(): Collection
    {
        return $this->blogPosts;
    }

    public function addBlogPost(BlogPost $blogPost): static
    {
        if (!$this->blogPosts->contains($blogPost)) {
            $this->blogPosts->add($blogPost);
            $blogPost->setAuthor($this);
        }

        return $this;
    }

    public function removeBlogPost(BlogPost $blogPost): static
    {
        if ($this->blogPosts->removeElement($blogPost)) {
            // set the owning side to null (unless already changed)
            if ($blogPost->getAuthor() === $this) {
                $blogPost->setAuthor(null);
            }
        }

        return $this;
    }

   

        // méthode imposée par UserInterface
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    // optionnelle (pas utilisée avec bcrypt/argon2i)
    public function getSalt(): ?string
    {
        return null;
    }

    // méthode imposée par UserInterface
    public function eraseCredentials(): void
    {
        // Si tu stockes des données sensibles temporaires, vide-les ici
    }

    public function getAddresses(): Collection
    {
        return $this->addresses;
    }


    /**
     * Retourne les adresses actives (non supprimées) de l'utilisateur.
     */
    private function getActiveAddresses(): Collection
    {
        return $this->addresses->filter(fn(Address $a) => !$a->isDeleted());
    }

    /**
     * Retourne les adresses d'un type donné : personal | business | relay.
     */
    #[Groups(['user:read'])]
    public function getAddressesByKind(string $kind): array
    {
        return $this->getActiveAddresses()
            ->filter(fn(Address $a) => $a->getAddressKind() === $kind)
            ->toArray();
    }

    /**
     * Retourne l'adresse par défaut pour un kind donné.
     */
    #[Groups(['user:read'])]
    public function getDefaultAddressByKind(string $kind): ?Address
    {
        foreach ($this->getAddressesByKind($kind) as $address) {
            if ($address->isDefault()) {
                return $address;
            }
        }
        return null;
    }


    public function addAddress(Address $address): static
    {
        if (!$this->addresses->contains($address)) {
            $this->addresses->add($address);
            $address->setOwner($this);
        }
        return $this;
    }

    public function removeAddress(Address $address): static
    {
        if ($this->addresses->removeElement($address)) {
            if ($address->getOwner() === $this) {
                $address->setOwner(null);
            }
        }
        return $this;
    }

    public function getAcceptTerms(): bool
    {
        return $this->acceptTerms;
    }

    public function setAcceptTerms(bool $acceptTerms): static
    {
        $this->acceptTerms = $acceptTerms;
        return $this;
    }

    public function isNewsletter(): bool
    {
        return $this->newsletter;
    }

    public function setNewsletter(bool $newsletter): static
    {
        $this->newsletter = $newsletter;
        return $this;
    }

    public function getConfirmationToken(): ?string
    {
        return $this->confirmationToken;
    }

    public function setConfirmationToken(?string $confirmationToken): static
    {
        $this->confirmationToken = $confirmationToken;
        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;
        return $this;
    }

    public function getResetPasswordToken(): ?string
    {
        return $this->resetPasswordToken;
    }

    public function setResetPasswordToken(?string $token): self
    {
        $this->resetPasswordToken = $token;
        return $this;
    }

    public function getResetPasswordRequestedAt(): ?\DateTimeInterface
    {
        return $this->resetPasswordRequestedAt;
    }

    public function setResetPasswordRequestedAt(?\DateTimeInterface $date): self
    {
        $this->resetPasswordRequestedAt = $date;
        return $this;
    }

    public function isGuest(): bool
    {
        return $this->isGuest;
    }

    public function setIsGuest(bool $isGuest): self
    {
        $this->isGuest = $isGuest;
        return $this;
    }

    
    public function getStripeCustomerId(): ?string { return $this->stripeCustomerId; }
    public function setStripeCustomerId(?string $id): self { $this->stripeCustomerId = $id; return $this; }

    // Getters/Setters
    public function getPromoCode(): ?string
    {
        return $this->promoCode;
    }

    public function setPromoCode(?string $promoCode): static
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
}
