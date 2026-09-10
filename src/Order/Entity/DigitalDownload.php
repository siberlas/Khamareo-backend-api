<?php

namespace App\Order\Entity;

use App\Catalog\Entity\Product;
use App\Order\Repository\DigitalDownloadRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Droit de téléchargement d'un livre numérique, créé à la confirmation de
 * paiement pour chaque ligne de commande numérique. Le lien envoyé par email
 * pointe vers /api/public/downloads/{token} ; le PDF watermarké réel est
 * stocké sur Cloudinary (ressource `raw`, `type: authenticated`) et servi via
 * une URL signée courte.
 */
#[ORM\Entity(repositoryClass: DigitalDownloadRepository::class)]
#[ORM\Table(name: 'digital_download')]
#[ORM\Index(columns: ['token'], name: 'idx_digital_download_token')]
class DigitalDownload
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['digital_download:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: OrderItem::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OrderItem $orderItem;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Order $customerOrder;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Product $product = null;

    #[ORM\Column(length: 180)]
    #[Groups(['digital_download:read'])]
    private string $email;

    #[ORM\Column(length: 64, unique: true)]
    private string $token;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['digital_download:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['digital_download:read'])]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    #[Groups(['digital_download:read'])]
    private int $maxDownloads = 1;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Groups(['digital_download:read'])]
    private int $downloadCount = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['digital_download:read'])]
    private ?\DateTimeImmutable $firstDownloadedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['digital_download:read'])]
    private ?\DateTimeImmutable $lastDownloadedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['digital_download:read'])]
    private ?\DateTimeImmutable $revokedAt = null;

    /** Horodatage du dernier envoi email réussi du lien de téléchargement. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['digital_download:read'])]
    private ?\DateTimeImmutable $emailSentAt = null;

    /** public_id Cloudinary du PDF watermarké livré à cet acheteur. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $deliveredFilePublicId = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->token = bin2hex(random_bytes(32));
    }

    public function getId(): Uuid { return $this->id; }

    public function getOrderItem(): OrderItem { return $this->orderItem; }
    public function setOrderItem(OrderItem $v): self { $this->orderItem = $v; return $this; }

    public function getCustomerOrder(): Order { return $this->customerOrder; }
    public function setCustomerOrder(Order $v): self { $this->customerOrder = $v; return $this; }

    public function getProduct(): ?Product { return $this->product; }
    public function setProduct(?Product $v): self { $this->product = $v; return $this; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $v): self { $this->email = $v; return $this; }

    public function getToken(): string { return $this->token; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(?\DateTimeImmutable $v): self { $this->expiresAt = $v; return $this; }

    public function getMaxDownloads(): int { return $this->maxDownloads; }
    public function setMaxDownloads(int $v): self { $this->maxDownloads = max(1, $v); return $this; }

    public function getDownloadCount(): int { return $this->downloadCount; }

    public function getFirstDownloadedAt(): ?\DateTimeImmutable { return $this->firstDownloadedAt; }
    public function getLastDownloadedAt(): ?\DateTimeImmutable { return $this->lastDownloadedAt; }

    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }
    public function revoke(): self { $this->revokedAt = new \DateTimeImmutable(); return $this; }

    public function getEmailSentAt(): ?\DateTimeImmutable { return $this->emailSentAt; }
    public function markEmailSent(): self { $this->emailSentAt = new \DateTimeImmutable(); return $this; }

    public function getDeliveredFilePublicId(): ?string { return $this->deliveredFilePublicId; }
    public function setDeliveredFilePublicId(?string $v): self { $this->deliveredFilePublicId = $v; return $this; }

    #[Groups(['digital_download:read'])]
    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < new \DateTimeImmutable();
    }

    #[Groups(['digital_download:read'])]
    public function isDownloadable(): bool
    {
        return $this->revokedAt === null
            && !$this->isExpired()
            && $this->downloadCount < $this->maxDownloads
            && $this->deliveredFilePublicId !== null;
    }

    /** Enregistre un téléchargement effectif (à appeler juste avant de servir le fichier). */
    public function registerDownload(): self
    {
        $now = new \DateTimeImmutable();
        $this->downloadCount++;
        $this->firstDownloadedAt ??= $now;
        $this->lastDownloadedAt = $now;

        return $this;
    }

    /**
     * Réémet un lien : nouveau token, compteur remis à zéro, expiration
     * repoussée. Utilisé par le renvoi admin (« l'acheteur n'a jamais reçu /
     * a perdu son fichier »).
     */
    public function reissue(?\DateTimeImmutable $expiresAt): self
    {
        $this->token = bin2hex(random_bytes(32));
        $this->downloadCount = 0;
        $this->revokedAt = null;
        $this->expiresAt = $expiresAt;

        return $this;
    }
}
