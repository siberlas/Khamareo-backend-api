<?php

namespace App\User\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;

use App\User\Repository\AddressRepository;
use App\User\State\AddressOwnerProcessor;
use App\User\State\AddressSoftDeleteProcessor;
use App\User\State\AddressSetDefaultProcessor;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use App\User\State\AddressProvider;
/**
 * Address entity :
 * - personal (adresse perso)
 * - business (adresse pro)
 * - relay (point relais)
 */
#[ORM\Entity(repositoryClass: AddressRepository::class)]
#[ApiResource(
    normalizationContext: ['groups' => ['address:read']],
    denormalizationContext: ['groups' => ['address:write']],
    operations:[
        new GetCollection(
            security: "is_granted('ROLE_USER')",
            provider: AddressProvider::class
        ),
        new Get(
            security: "is_granted('ROLE_USER')",
            provider: AddressProvider::class
        ),
        new Post(
            security: "is_granted('ROLE_USER')", 
            processor: AddressOwnerProcessor::class
        ),
        new Patch(
            security: "is_granted('ROLE_USER')",
            provider: AddressProvider::class,
            processor: AddressOwnerProcessor::class
        ),
        new Patch(
            uriTemplate: '/addresses/{id}/set-default',
            name: 'address_set_default',
            security: "is_granted('ROLE_USER')",
            provider: AddressProvider::class,
            processor: AddressSetDefaultProcessor::class
        ),
        new Delete(
            security: "is_granted('ROLE_USER')",
            provider: AddressProvider::class,
            processor: AddressSoftDeleteProcessor::class
        ),
    ]
)]
#[ApiFilter(SearchFilter::class, properties: ['type' => 'exact', 'addressKind' => 'exact'])]
class Address
{
    // ===================================
    // ID
    // ===================================
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['address:read'])]
    private ?int $id = null;

    // ===================================
    // Address kind (personal | business | relay)
    // ===================================
    #[ORM\Column(length: 20)]
    #[Groups(['address:read', 'address:write', 'order:read'])]
    private string $addressKind = 'personal';

    // ===================================
    // Base informations (shared fields)
    // ===================================
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: "L'adresse est obligatoire.")]
    #[Groups(['address:read', 'address:write', 'order:read'])]
    private string $streetAddress;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: "La ville est obligatoire.")]
    #[Groups(['address:read', 'address:write', 'order:read'])]
    private string $city;

    #[ORM\Column(length: 10)]
    #[Assert\Regex(pattern: "/^[0-9A-Za-z -]{3,10}$/", message: "Le code postal doit être valide.")]
    #[Groups(['address:read', 'address:write', 'order:read'])]
    private string $postalCode;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: "Le pays est obligatoire.")]
    #[Groups(['address:read', 'address:write', 'order:read'])]
    private string $country;

    /** État / Province — obligatoire pour US et CA */
    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['address:read', 'address:write', 'order:read'])]
    private ?string $state = null;

    #[ORM\Column(length: 150, nullable: true)]
    #[Groups(['address:read', 'address:write', 'order:read'])]
    private ?string $label = null;

    // ===================================
    // PERSONAL fields
    // ===================================
    #[ORM\Column(length: 10, nullable: true)]
    #[Groups(['address:read', 'address:write'])]
    private ?string $civility = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['address:read', 'address:write', 'order:read'])]
    private ?string $firstName = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['address:read', 'address:write', 'order:read'])]
    private ?string $lastName = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['address:read', 'address:write', 'order:read'])]
    private ?string $phone = null;

    // ===================================
    // BUSINESS fields
    // ===================================
    #[ORM\Column(type: "boolean")]
    #[Groups(['address:read', 'address:write'])]
    private bool $isBusiness = false;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['address:read', 'address:write', 'order:read'])]
    private ?string $companyName = null;

    // ===================================
    // RELAY point fields
    // ===================================
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['address:read', 'address:write', 'order:read'])]
    private bool $isRelayPoint = false;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    #[Groups(['address:read', 'address:write', 'order:read'])]
    private ?string $relayPointId = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    #[Groups(['address:read', 'address:write', 'order:read'])]
    private ?string $relayCarrier = null;

    // ===================================
    // Geocoding fields
    // ===================================
    #[ORM\Column(type: 'float', nullable: true)]
    #[Groups(['address:read', 'address:write'])]
    private ?float $latitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    #[Groups(['address:read', 'address:write'])]
    private ?float $longitude = null;

    // ===================================
    // Owner & metadata
    // ===================================
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['address:read'])]
    private ?User $owner = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['address:read', 'address:write'])]
    private bool $isDefault = false;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $deletedAt = null;

    // ===================================
    // Getters / Setters
    // ===================================

    public function getId(): ?int { return $this->id; }

    public function getAddressKind(): string { return $this->addressKind; }
    public function setAddressKind(string $kind): self
    {
        $this->addressKind = $kind;
        return $this;
    }

    public function getStreetAddress(): string { return $this->streetAddress; }
    public function setStreetAddress(string $street): self { $this->streetAddress = $street; return $this; }

    public function getCity(): string { return $this->city; }
    public function setCity(string $city): self { $this->city = $city; return $this; }

    public function getPostalCode(): string { return $this->postalCode; }
    public function setPostalCode(string $pc): self { $this->postalCode = $pc; return $this; }

    public function getCountry(): string { return $this->country; }
    public function setCountry(string $country): self { $this->country = $country; return $this; }

    public function getState(): ?string { return $this->state; }
    public function setState(?string $state): self { $this->state = $state; return $this; }

    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $label): self { $this->label = $label; return $this; }

    public function getCivility(): ?string { return $this->civility; }
    public function setCivility(?string $civility): self { $this->civility = $civility; return $this; }

    public function getFirstName(): ?string { return $this->firstName; }
    public function setFirstName(?string $fn): self { $this->firstName = $fn; return $this; }

    public function getLastName(): ?string { return $this->lastName; }
    public function setLastName(?string $ln): self { $this->lastName = $ln; return $this; }

    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $p): self { $this->phone = $p; return $this; }

    public function isBusiness(): bool { return $this->isBusiness; }
    public function getIsBusiness(): bool { return $this->isBusiness; }

    public function setIsBusiness(bool $b): self { $this->isBusiness = $b; return $this; }

    public function getCompanyName(): ?string { return $this->companyName; }
    public function setCompanyName(?string $cn): self { $this->companyName = $cn; return $this; }

    public function isRelayPoint(): bool { return $this->isRelayPoint; }
    public function setIsRelayPoint(bool $v): self { $this->isRelayPoint = $v; return $this; }

    public function getRelayPointId(): ?string { return $this->relayPointId; }
    public function setRelayPointId(?string $id): self { $this->relayPointId = $id; return $this; }

    public function getRelayCarrier(): ?string { return $this->relayCarrier; }
    public function setRelayCarrier(?string $carrier): self { $this->relayCarrier = $carrier; return $this; }

    public function getLatitude(): ?float { return $this->latitude; }
    public function setLatitude(?float $latitude): self { $this->latitude = $latitude; return $this; }

    public function getLongitude(): ?float { return $this->longitude; }
    public function setLongitude(?float $longitude): self { $this->longitude = $longitude; return $this; }

    public function getOwner(): ?User { return $this->owner; }
    public function setOwner(?User $owner): self { $this->owner = $owner; return $this; }

    public function isDefault(): bool { return $this->isDefault; }
    public function getIsDefault(): bool { return $this->isDefault; }

    public function setIsDefault(bool $default): self { $this->isDefault = $default; return $this; }

    public function softDelete(): void { $this->deletedAt = new \DateTimeImmutable(); }
    public function isDeleted(): bool { return $this->deletedAt !== null; }

    public function getFullAddress(): string
    {
        return sprintf('%s, %s %s, %s', $this->streetAddress, $this->postalCode, $this->city, $this->country);
    }

    // ===================================
    // VALIDATION DYNAMIQUE
    // ===================================
    #[Assert\Callback]
    public function validateAddress(ExecutionContextInterface $context): void
    {
        // LABEL obligatoire
        if (empty($this->label)) {
            $context->buildViolation("Veuillez donner un nom à cette adresse (ex : Maison, Bureau).")
                ->atPath("label")
                ->addViolation();
        }

        // CASE 1 → POINT RELAIS
        if ($this->addressKind === 'relay') {
            if (!$this->isRelayPoint) {
                $context->buildViolation("Pour un point relais, isRelayPoint doit être true.")
                    ->atPath("isRelayPoint")
                    ->addViolation();
            }
            if (empty($this->relayPointId)) {
                $context->buildViolation("L'identifiant du point relais est obligatoire.")
                    ->atPath("relayPointId")
                    ->addViolation();
            }
            return; // aucun contrôle sur firstname/lastname/etc.
        }

        // CASE 2 → BUSINESS
        if ($this->addressKind === 'business') {
            if (empty($this->companyName)) {
                $context->buildViolation("Le nom de la société est obligatoire pour une adresse professionnelle.")
                    ->atPath("companyName")
                    ->addViolation();
            }
            return;
        }

        // CASE 3 → PERSONAL
        if ($this->addressKind === 'personal') {
            if (empty($this->civility)) {
                $context->buildViolation("La civilité est obligatoire.")
                    ->atPath("civility")
                    ->addViolation();
            }
            if (empty($this->firstName)) {
                $context->buildViolation("Le prénom est obligatoire.")
                    ->atPath("firstName")
                    ->addViolation();
            }
            if (empty($this->lastName)) {
                $context->buildViolation("Le nom est obligatoire.")
                    ->atPath("lastName")
                    ->addViolation();
            }
        }
    }
}
