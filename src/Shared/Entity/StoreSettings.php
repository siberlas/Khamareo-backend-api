<?php

namespace App\Shared\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'store_settings')]
class StoreSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // ── Expédition ────────────────────────────────────────────────────────────

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $dispatchMinDays = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $dispatchMaxDays = null;

    #[ORM\Column(length: 20)]
    private string $dispatchDaysUnit = 'working_days';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $dispatchNote = null;

    // ── Boutique ──────────────────────────────────────────────────────────────

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $shopName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $shopEmail = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $shopPhone = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $shopAddress = null;

    /** Horaires d'ouverture en texte libre (ex: "Lun–Ven : 9h–18h\nSam : 10h–15h") */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $shopHours = null;

    // ── Réseaux sociaux ───────────────────────────────────────────────────────

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $socialInstagram = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $socialTiktok = null;

    // ── Timestamp ─────────────────────────────────────────────────────────────

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    // ── Getters/setters Expédition ─────────────────────────────────────────

    public function getDispatchMinDays(): ?int { return $this->dispatchMinDays; }
    public function setDispatchMinDays(?int $v): self { $this->dispatchMinDays = $v; return $this; }

    public function getDispatchMaxDays(): ?int { return $this->dispatchMaxDays; }
    public function setDispatchMaxDays(?int $v): self { $this->dispatchMaxDays = $v; return $this; }

    public function getDispatchDaysUnit(): string { return $this->dispatchDaysUnit; }
    public function setDispatchDaysUnit(string $v): self { $this->dispatchDaysUnit = $v; return $this; }

    public function getDispatchNote(): ?string { return $this->dispatchNote; }
    public function setDispatchNote(?string $v): self { $this->dispatchNote = $v; return $this; }

    // ── Getters/setters Boutique ───────────────────────────────────────────

    public function getShopName(): ?string { return $this->shopName; }
    public function setShopName(?string $v): self { $this->shopName = $v; return $this; }

    public function getShopEmail(): ?string { return $this->shopEmail; }
    public function setShopEmail(?string $v): self { $this->shopEmail = $v; return $this; }

    public function getShopPhone(): ?string { return $this->shopPhone; }
    public function setShopPhone(?string $v): self { $this->shopPhone = $v; return $this; }

    public function getShopAddress(): ?string { return $this->shopAddress; }
    public function setShopAddress(?string $v): self { $this->shopAddress = $v; return $this; }

    public function getShopHours(): ?string { return $this->shopHours; }
    public function setShopHours(?string $v): self { $this->shopHours = $v; return $this; }

    // ── Getters/setters Réseaux sociaux ───────────────────────────────────

    public function getSocialInstagram(): ?string { return $this->socialInstagram; }
    public function setSocialInstagram(?string $v): self { $this->socialInstagram = $v; return $this; }

    public function getSocialTiktok(): ?string { return $this->socialTiktok; }
    public function setSocialTiktok(?string $v): self { $this->socialTiktok = $v; return $this; }

    // ── Timestamp ─────────────────────────────────────────────────────────

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $v): self { $this->updatedAt = $v; return $this; }
}
