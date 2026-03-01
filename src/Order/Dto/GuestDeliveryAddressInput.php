<?php

namespace App\Order\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class GuestDeliveryAddressInput
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

    #[Assert\Regex(
        pattern: '/^[0-9+\(\)\.\-\s]{7,20}$/',
        message: 'Numéro de téléphone invalide.',
    )]
    #[Groups(['guest-user'])]
    public ?string $phone = null;

    #[Assert\Choice(choices: ['personal', 'business', 'relay'])]
    #[Groups(['guest-user'])]
    public string $addressKind = 'personal';

    #[Groups(['guest-user'])]
    public bool $isRelayPoint = false;

    #[Groups(['guest-user'])]
    public ?string $relayPointId = null;

    #[Groups(['guest-user'])]
    public ?string $relayCarrier = null;

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

    #[Assert\Length(min: 2, max: 100)]
    #[Assert\Regex(
        pattern: '/^[A-Za-z0-9\-\s]{2,100}$/',
        message: "Le champ État/Province est invalide.",
    )]
    #[Groups(['guest-user'])]
    public ?string $state = null;

    #[Assert\Callback]
    public function validatePhoneRequired(ExecutionContextInterface $context): void
    {
        if ($this->addressKind === 'relay' || $this->isRelayPoint) {
            return;
        }

        $phone = $this->phone !== null ? trim($this->phone) : '';
        if ($phone === '') {
            $context->buildViolation('Le numéro de téléphone est obligatoire.')
                ->atPath('phone')
                ->addViolation();
        }
    }

    #[Assert\Callback]
    public function validateRelayFields(ExecutionContextInterface $context): void
    {
        if ($this->addressKind !== 'relay') {
            return;
        }

        if (!$this->isRelayPoint) {
            $context->buildViolation('Pour un point relais, isRelayPoint doit être true.')
                ->atPath('isRelayPoint')
                ->addViolation();
        }

        if (empty($this->relayPointId)) {
            $context->buildViolation('relayPointId est requis pour un point relais.')
                ->atPath('relayPointId')
                ->addViolation();
        }

        if (empty($this->relayCarrier)) {
            $context->buildViolation('relayCarrier est requis pour un point relais.')
                ->atPath('relayCarrier')
                ->addViolation();
        }
    }
}
