<?php

namespace App\Contact\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Contact\Repository\ContactMessageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Serializer\Annotation\Groups;
use App\Contact\State\ContactMessageProcessor;

#[ORM\Entity(repositoryClass: ContactMessageRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Post(
            security: "is_granted('PUBLIC_ACCESS')",
            processor: ContactMessageProcessor::class,
            denormalizationContext: ['groups' => ['contact:write']],
            normalizationContext: ['groups' => ['contact:read']]
        )
    ]
)]
class ContactMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['contact:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ContactConversation::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ContactConversation $conversation;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 100)]
    #[Groups(['contact:write', 'contact:read'])]
    private string $name;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Groups(['contact:write', 'contact:read'])]
    private string $email;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Regex(pattern: '/^[0-9+\(\)\.\-\s]{7,20}$/')]
    #[Groups(['contact:write', 'contact:read'])]
    private ?string $phone = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 200)]
    #[Groups(['contact:write', 'contact:read'])]
    private string $subject;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Assert\Length(min: 10, max: 5000)]
    #[Groups(['contact:write', 'contact:read'])]
    private string $message;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['contact:write', 'contact:read'])]
    private ?string $orderNumber = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isAdminReply = false;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getConversation(): ContactConversation { return $this->conversation; }
    public function setConversation(ContactConversation $conversation): self { $this->conversation = $conversation; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): self { $this->email = $email; return $this; }

    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): self { $this->phone = $phone; return $this; }

    public function getSubject(): string { return $this->subject; }
    public function setSubject(string $subject): self { $this->subject = $subject; return $this; }

    public function getMessage(): string { return $this->message; }
    public function setMessage(string $message): self { $this->message = $message; return $this; }

    public function getOrderNumber(): ?string { return $this->orderNumber; }
    public function setOrderNumber(?string $orderNumber): self { $this->orderNumber = $orderNumber; return $this; }

    public function isAdminReply(): bool { return $this->isAdminReply; }
    public function setIsAdminReply(bool $isAdminReply): self { $this->isAdminReply = $isAdminReply; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }
}
