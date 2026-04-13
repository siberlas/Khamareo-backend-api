<?php

namespace App\Order\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Serializer\Annotation\Groups;

final class GuestCartAddressInput
{
    #[Assert\NotBlank(message:"Le prénom est obligatoire")]
    #[Assert\Length(min: 2, max: 100)]
    #[Assert\Regex(
        pattern: '/^[\p{L}][\p{L}\p{M} \'\-]+$/u',
        message: 'Prénom invalide.',
    )]
    #[Groups(['guest-user'])]
    public ?string $firstName = null;

    #[Assert\NotBlank(message:"le Nom est obligatoire")]
    #[Assert\Length(min: 2, max: 100)]
    #[Assert\Regex(
        pattern: '/^[\p{L}][\p{L}\p{M} \'\-]+$/u',
        message: 'Nom invalide.',
    )]
    #[Groups(['guest-user'])]
    public ?string $lastName = null;

    #[Assert\NotBlank(message: "L'adresse email est obligatoire")]
    #[Assert\Email(message:"Le format de l'adresse email n'est pas valide")]
    #[Assert\Length(max: 180)]
    #[Groups(['guest-user'])]
    public ?string $email = null;

    #[Assert\NotBlank(message: "Le numéro de téléphone est obligatoire")]
    #[Assert\Regex(
        pattern: '/^[0-9+\(\)\.\-\s]{7,20}$/',
        message: 'Numéro de téléphone invalide.',
    )]
    #[Groups(['guest-user'])]
    public ?string $phone = null;

    #[Assert\NotBlank(message: "l'adresse est obligatoire")]
    #[Assert\Length(min: 3, max: 255)]
    #[Groups(['guest-user'])]
    public ?string $streetAddress = null;

    #[Assert\NotBlank(message:"le Code postal est obligatoire")]
    #[Assert\Regex(
        pattern: '/^[A-Za-z0-9\-\s]{3,12}$/',
        message: 'Code postal invalide.',
    )]
    #[Groups(['guest-user'])]
    public ?string $postalCode = null;

    #[Assert\NotBlank(message:"La ville est obligatoire")]
    #[Assert\Length(min: 2, max: 100)]
    #[Groups(['guest-user'])]
    public ?string $city = null;

    #[Assert\NotBlank(message: "Le pays est obligatoire")]
    #[Assert\Length(min: 2, max: 2)]
    #[Assert\Regex(
        pattern: '/^[A-Za-z]{2}$/',
        message: 'Le pays doit être un code ISO-2.',
    )]
    #[Groups(['guest-user'])]
    public ?string $country = null;

    // ✅ NOUVEAU : Consentement RGPD
    #[Assert\IsTrue(message: 'Vous devez accepter les conditions générales pour continuer.')]
    #[Groups(['guest-user'])]
    public bool $hasAcceptedTerms = false;
}