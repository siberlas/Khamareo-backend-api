<?php

namespace App\Order\Entity;

use App\Order\Repository\OrderMessageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: OrderMessageRepository::class)]
class OrderMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false)]
    private Order $order;

    #[ORM\Column(length: 200)]
    #[Groups(['order:read'])]
    private string $subject;

    #[ORM\Column(type: 'text')]
    #[Groups(['order:read'])]
    private string $message;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['order:read'])]
    private ?string $attachmentPath = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['order:read'])]
    private ?string $attachmentFilename = null;

    #[ORM\Column]
    #[Groups(['order:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getOrder(): Order { return $this->order; }
    public function setOrder(Order $order): self { $this->order = $order; return $this; }

    public function getSubject(): string { return $this->subject; }
    public function setSubject(string $subject): self { $this->subject = $subject; return $this; }

    public function getMessage(): string { return $this->message; }
    public function setMessage(string $message): self { $this->message = $message; return $this; }

    public function getAttachmentPath(): ?string { return $this->attachmentPath; }
    public function setAttachmentPath(?string $attachmentPath): self { $this->attachmentPath = $attachmentPath; return $this; }

    public function getAttachmentFilename(): ?string { return $this->attachmentFilename; }
    public function setAttachmentFilename(?string $attachmentFilename): self { $this->attachmentFilename = $attachmentFilename; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
