<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Serializer\Annotation\Groups;

class ChangePasswordRequest
{
    #[Assert\NotBlank(message: "Le mot de passe actuel est requis.")]
    #[Groups(['change-password'])]
    public ?string $currentPassword = null;

    #[Assert\NotBlank(message: "Le nouveau mot de passe est requis.")]
    #[Assert\Length(min: 6, minMessage: "Le mot de passe doit faire au moins 6 caractères.")]
    #[Groups(['change-password'])]
    public ?string $newPassword = null;
}
