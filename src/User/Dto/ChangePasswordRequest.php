<?php

namespace App\User\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Serializer\Annotation\Groups;

class ChangePasswordRequest
{
    #[Assert\NotBlank(message: "Le mot de passe actuel est requis.")]
    #[Groups(['change-password'])]
    public ?string $currentPassword = null;

    #[Assert\NotBlank(message: "Le nouveau mot de passe est requis.")]
    #[Assert\Length(min: 8, minMessage: "Le mot de passe doit faire au moins 8 caractères.")]
    #[Assert\Regex(pattern: '/[A-Z]/', message: "Le mot de passe doit contenir au moins une majuscule.")]
    #[Assert\Regex(pattern: '/[a-z]/', message: "Le mot de passe doit contenir au moins une minuscule.")]
    #[Assert\Regex(pattern: '/[0-9]/', message: "Le mot de passe doit contenir au moins un chiffre.")]
    #[Groups(['change-password'])]
    public ?string $newPassword = null;
}
