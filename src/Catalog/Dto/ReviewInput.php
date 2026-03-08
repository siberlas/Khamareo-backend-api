<?php

namespace App\Catalog\Dto;

use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class ReviewInput
{
    #[Groups(['review:write'])]
    #[Assert\NotBlank(message: 'Le produit est obligatoire.')]
    public ?string $product = null;

    #[Groups(['review:write'])]
    #[Assert\NotBlank(message: 'La note est obligatoire.')]
    #[Assert\Range(min: 1, max: 5, notInRangeMessage: 'La note doit être comprise entre 1 et 5.')]
    public ?int $rating = null;

    #[Groups(['review:write'])]
    #[Assert\NotBlank(message: 'Le commentaire est obligatoire.')]
    #[Assert\Length(min: 5, max: 1000, minMessage: 'Le commentaire doit faire au moins {{ limit }} caractères.', maxMessage: 'Le commentaire ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $comment = null;

    #[Groups(['review:write'])]
    #[Assert\NotBlank(message: 'Le nom est obligatoire.', groups: ['guest'])]
    #[Assert\Length(min: 2, max: 255, minMessage: 'Le nom doit faire au moins {{ limit }} caractères.', groups: ['guest'])]
    public ?string $name = null;

    #[Groups(['review:write'])]
    #[Assert\NotBlank(message: "L'adresse email est obligatoire.", groups: ['guest'])]
    #[Assert\Email(message: "L'adresse email '{{ value }}' n'est pas valide.", groups: ['guest'])]
    public ?string $email = null;
}
