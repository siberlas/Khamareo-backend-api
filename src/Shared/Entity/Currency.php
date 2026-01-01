<?php
// src/Entity/Currency.php

namespace App\Shared\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'currency')]
#[ApiResource(
    operations: [
        new Get(),
        new GetCollection()
    ],
    normalizationContext: ['groups' => ['currency:read']],
    paginationEnabled: false
)]
class Currency
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['currency:read', 'product:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: 3, unique: true)]
    #[Groups(['currency:read', 'product:read'])]
    private string $code;

    #[ORM\Column(length: 5)]
    #[Groups(['currency:read', 'product:read'])]
    private string $symbol;

    #[ORM\Column(length: 50)]
    #[Groups(['currency:read'])]
    private string $name;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    #[Groups(['currency:read'])]
    private ?string $exchangeRateToEur = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    #[Groups(['currency:read'])]
    private bool $isDefault = false;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    // Getters & Setters
    public function getId(): ?Uuid { return $this->id; }

    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self
    {
        $this->code = $code;
        return $this;
    }

    public function getSymbol(): string { return $this->symbol; }
    public function setSymbol(string $symbol): self
    {
        $this->symbol = $symbol;
        return $this;
    }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getExchangeRateToEur(): ?string { return $this->exchangeRateToEur; }
    public function setExchangeRateToEur(?string $rate): self
    {
        $this->exchangeRateToEur = $rate;
        return $this;
    }

    public function getIsDefault(): bool { return $this->isDefault; }
    public function setIsDefault(bool $isDefault): self
    {
        $this->isDefault = $isDefault;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}