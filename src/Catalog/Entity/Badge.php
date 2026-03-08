<?php
namespace App\Catalog\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;
use ApiPlatform\Metadata\ApiResource;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[UniqueEntity(fields: ['code'], message: 'Un badge avec ce code existe déjà.')]
#[ApiResource(
    normalizationContext: ['groups' => ['badge:read']],
    denormalizationContext: ['groups' => ['badge:write']],
    operations: [
        new \ApiPlatform\Metadata\GetCollection(security: "is_granted('PUBLIC_ACCESS')"),
        new \ApiPlatform\Metadata\Get(security: "is_granted('PUBLIC_ACCESS')"),
        new \ApiPlatform\Metadata\Post(security: "is_granted('ROLE_ADMIN')"),
        new \ApiPlatform\Metadata\Patch(security: "is_granted('ROLE_ADMIN')"),
        new \ApiPlatform\Metadata\Delete(security: "is_granted('ROLE_ADMIN')"),
    ]
)]
class Badge
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['badge:read', 'product:read', 'product:write'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: 100, unique: true)]
    #[Groups(['badge:read', 'badge:write', 'product:read'])]
    #[Assert\NotBlank(message: 'Le code est obligatoire.')]
    #[Assert\Length(max: 100, maxMessage: 'Le code ne peut pas dépasser {{ limit }} caractères.')]
    private string $code;

    #[ORM\Column(length: 255)]
    #[Groups(['badge:read', 'badge:write', 'product:read'])]
    #[Assert\NotBlank(message: 'Le label est obligatoire.')]
    private string $label;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): ?Uuid { return $this->id; }

    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = $code; return $this; }

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): self { $this->label = $label; return $this; }
}
