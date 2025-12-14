<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\ShippingLabelRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

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
    private ?string $provider = null; // ex: "Colissimo"

    #[ORM\Column(length: 150)]
    #[Groups(['shipping_label:read'])]
    private ?string $trackingNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['shipping_label:read'])]
    private ?string $filePath = null; // lien vers PDF généré

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

    // --- Getters & Setters ---
    public function getId(): ?int { return $this->id; }
    public function getProvider(): ?string { return $this->provider; }
    public function setProvider(string $provider): self { $this->provider = $provider; return $this; }
    public function getTrackingNumber(): ?string { return $this->trackingNumber; }
    public function setTrackingNumber(string $trackingNumber): self { $this->trackingNumber = $trackingNumber; return $this; }
    public function getFilePath(): ?string { return $this->filePath; }
    public function setFilePath(?string $filePath): self { $this->filePath = $filePath; return $this; }
    public function getOrder(): ?Order { return $this->order; }
    public function setOrder(Order $order): self { $this->order = $order; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
