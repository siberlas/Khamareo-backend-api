<?php
// src/Entity/ShippingLabel.php

namespace App\Shipping\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Shipping\Repository\ShippingLabelRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use App\Order\Entity\Order;

#[ORM\Entity(repositoryClass: ShippingLabelRepository::class)]
#[ApiResource(
    normalizationContext: ['groups' => ['shipping_label:read']],
    denormalizationContext: ['groups' => ['shipping_label:write']]
)]
class ShippingLabel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['shipping_label:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Groups(['shipping_label:read', 'shipping_label:write'])]
    private ?string $provider = null;

    #[ORM\Column(length: 150)]
    #[Groups(['shipping_label:read'])]
    private ?string $trackingNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['shipping_label:read'])]
    private ?string $filePath = null; // Deprecated, use labelUrl

    // 🆕 Nouveaux champs
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['shipping_label:read'])]
    private ?string $labelUrl = null; // URL Cloudinary étiquette

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['shipping_label:read'])]
    private ?array $labelData = null; // Réponse brute API

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['shipping_label:read'])]
    private ?string $documentUrl = null; // URL Cloudinary PDF 3-en-1

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['shipping_label:read'])]
    private ?string $preparationSheetUrl = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['shipping_label:read'])]
    private ?string $deliverySlipUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['shipping_label:read'])]
    private ?string $cn23Url = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['shipping_label:read'])]
    private ?\DateTimeImmutable $generatedAt = null;

    #[ORM\OneToOne(inversedBy: 'shippingLabel', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['shipping_label:read', 'shipping_label:write'])]
    private ?Order $order = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['shipping_label:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // Getters & Setters
    public function getId(): ?int { return $this->id; }
    
    public function getProvider(): ?string { return $this->provider; }
    public function setProvider(string $provider): self { $this->provider = $provider; return $this; }
    
    public function getTrackingNumber(): ?string { return $this->trackingNumber; }
    public function setTrackingNumber(string $trackingNumber): self { $this->trackingNumber = $trackingNumber; return $this; }
    
    public function getFilePath(): ?string { return $this->filePath; }
    public function setFilePath(?string $filePath): self { $this->filePath = $filePath; return $this; }
    
    public function getLabelUrl(): ?string { return $this->labelUrl; }
    public function setLabelUrl(?string $labelUrl): self { $this->labelUrl = $labelUrl; return $this; }
    
    public function getLabelData(): ?array { return $this->labelData; }
    public function setLabelData(?array $labelData): self { $this->labelData = $labelData; return $this; }
    
    public function getDocumentUrl(): ?string { return $this->documentUrl; }
    public function setDocumentUrl(?string $documentUrl): self { $this->documentUrl = $documentUrl; return $this; }
    
    public function getOrder(): ?Order { return $this->order; }
    public function setOrder(Order $order): self { $this->order = $order; return $this; }
    
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    public function getPreparationSheetUrl(): ?string { return $this->preparationSheetUrl; }
    public function setPreparationSheetUrl(?string $preparationSheetUrl): self { $this->preparationSheetUrl = $preparationSheetUrl; return $this; }

    public function getDeliverySlipUrl(): ?string { return $this->deliverySlipUrl; }
    public function setDeliverySlipUrl(?string $deliverySlipUrl): self { $this->deliverySlipUrl = $deliverySlipUrl; return $this; }

    public function getCn23Url(): ?string { return $this->cn23Url; }
    public function setCn23Url(?string $cn23Url): self { $this->cn23Url = $cn23Url; return $this; }

    public function getGeneratedAt(): ?\DateTimeImmutable { return $this->generatedAt; }
    public function setGeneratedAt(?\DateTimeImmutable $generatedAt): self { $this->generatedAt = $generatedAt; return $this; }
}