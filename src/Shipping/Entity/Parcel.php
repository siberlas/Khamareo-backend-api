<?php

namespace App\Shipping\Entity;

use App\Shipping\Repository\ParcelRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Serializer\Annotation\Groups;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Patch;
use App\Order\Entity\Order;

#[ORM\Entity(repositoryClass: ParcelRepository::class)]
#[ApiResource(
    normalizationContext: ['groups' => ['parcel:read']],
    denormalizationContext: ['groups' => ['parcel:write']],
    operations: [
        new GetCollection(security: "is_granted('ROLE_ADMIN')"),
        new Get(security: "is_granted('ROLE_ADMIN')"),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Patch(security: "is_granted('ROLE_ADMIN')")
    ]
)]
class Parcel
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['parcel:read', 'order:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'parcels')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['parcel:read', 'parcel:write'])]
    private ?Order $order = null;

    #[ORM\Column(type: 'integer')]
    #[Groups(['parcel:read', 'parcel:write', 'order:read'])]
    private int $parcelNumber = 1; // 1, 2, 3...

    /**
     * Poids total du colis en GRAMMES
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['parcel:read', 'parcel:write', 'order:read'])]
    private ?int $weightGrams = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    #[Groups(['parcel:read', 'order:read'])]
    private ?string $trackingNumber = null;

    /**
     * Chemin vers le PDF de l'étiquette (stocké localement ou sur S3/Cloudinary)
     */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['parcel:read','order:read'])]
    private ?string $labelPdfPath = null;

    /**
     * Chemin vers le PDF du bon de livraison (par colis)
     */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['parcel:read', 'order:read'])]
    private ?string $deliverySlipPdfPath = null;

    /**
     * Chemin vers le PDF du CN23 (pour DOM-TOM et international)
     */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['parcel:read','order:read'])]
    private ?string $cn23PdfPath = null;

    /**
     * Chemin vers la facture commerciale (pour hors UE)
     */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['parcel:read'])]
    private ?string $invoicePdfPath = null;

    /**
     * Statut du colis
     * - pending: en attente de préparation
     * - labeled: étiquette générée
     * - shipped: expédié
     * - in_transit: en cours de livraison
     * - delivered: livré
     * - issue: problème détecté
     */
    #[ORM\Column(type: 'string', length: 50)]
    #[Groups(['parcel:read', 'parcel:write', 'order:read'])]
    private string $status = 'pending';

    #[ORM\OneToMany(mappedBy: 'parcel', targetEntity: ParcelItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['parcel:read'])]
    private Collection $items;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['parcel:read'])]
    private ?\DateTimeImmutable $labelGeneratedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['parcel:read'])]
    private ?\DateTimeImmutable $shippedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['parcel:read'])]
    private ?\DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['parcel:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->items = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    // Getters/Setters
    public function getId(): ?Uuid { return $this->id; }

    public function getOrder(): ?Order { return $this->order; }
    public function setOrder(?Order $order): self 
    { 
        $this->order = $order; 
        return $this; 
    }

    public function getParcelNumber(): int { return $this->parcelNumber; }
    public function setParcelNumber(int $parcelNumber): self 
    { 
        $this->parcelNumber = $parcelNumber; 
        return $this; 
    }

    public function getWeightGrams(): ?int { return $this->weightGrams; }
    public function setWeightGrams(?int $weightGrams): self 
    { 
        $this->weightGrams = $weightGrams; 
        return $this; 
    }

    /**
     * Poids en kilogrammes (pour affichage)
     */
    #[Groups(['parcel:read', 'order:read'])]
    public function getWeightKg(): ?float
    {
        if ($this->weightGrams === null) {
            return null;
        }
        return round($this->weightGrams / 1000, 3);
    }

    public function getTrackingNumber(): ?string { return $this->trackingNumber; }
    public function setTrackingNumber(?string $trackingNumber): self 
    { 
        $this->trackingNumber = $trackingNumber; 
        return $this; 
    }

    public function getLabelPdfPath(): ?string { return $this->labelPdfPath; }
    public function setLabelPdfPath(?string $labelPdfPath): self 
    { 
        $this->labelPdfPath = $labelPdfPath; 
        return $this; 
    }

    public function getDeliverySlipPdfPath(): ?string { return $this->deliverySlipPdfPath; }
    public function setDeliverySlipPdfPath(?string $deliverySlipPdfPath): self
    {
        $this->deliverySlipPdfPath = $deliverySlipPdfPath;
        return $this;
    }

    public function getCn23PdfPath(): ?string { return $this->cn23PdfPath; }
    public function setCn23PdfPath(?string $cn23PdfPath): self 
    { 
        $this->cn23PdfPath = $cn23PdfPath; 
        return $this; 
    }

    public function getInvoicePdfPath(): ?string { return $this->invoicePdfPath; }
    public function setInvoicePdfPath(?string $invoicePdfPath): self 
    { 
        $this->invoicePdfPath = $invoicePdfPath; 
        return $this; 
    }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self 
    { 
        $this->status = $status; 
        return $this; 
    }

    /**
     * @return Collection<int, ParcelItem>
     */
    public function getItems(): Collection { return $this->items; }

    public function addItem(ParcelItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setParcel($this);
        }
        return $this;
    }

    public function removeItem(ParcelItem $item): self
    {
        if ($this->items->removeElement($item)) {
            if ($item->getParcel() === $this) {
                $item->setParcel(null);
            }
        }
        return $this;
    }

    public function getLabelGeneratedAt(): ?\DateTimeImmutable { return $this->labelGeneratedAt; }
    public function setLabelGeneratedAt(?\DateTimeImmutable $labelGeneratedAt): self 
    { 
        $this->labelGeneratedAt = $labelGeneratedAt; 
        return $this; 
    }

    public function getShippedAt(): ?\DateTimeImmutable { return $this->shippedAt; }
    public function setShippedAt(?\DateTimeImmutable $shippedAt): self 
    { 
        $this->shippedAt = $shippedAt; 
        return $this; 
    }

    public function getDeliveredAt(): ?\DateTimeImmutable { return $this->deliveredAt; }
    public function setDeliveredAt(?\DateTimeImmutable $deliveredAt): self 
    { 
        $this->deliveredAt = $deliveredAt; 
        return $this; 
    }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
