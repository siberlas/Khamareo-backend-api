<?php

namespace App\Order\Dto;

use Symfony\Component\Serializer\Annotation\Groups;

/**
 * Structure renvoyée pour un invité identifié par son guestToken.
 */
final class GuestCheckoutView
{
    #[Groups(['guest-user'])]
    public ?string $guestToken = null;

    #[Groups(['guest-user'])]
    public ?string $userEmail = null;

    #[Groups(['guest-user'])]
    public ?string $firstName = null;

    #[Groups(['guest-user'])]
    public ?string $lastName = null;

    #[Groups(['guest-user'])]
    public ?string $phone = null;

    #[Groups(['guest-user'])]
    public ?array $address = null;

    #[Groups(['guest-user'])]
    public ?array $cartItems = null;

    #[Groups(['guest-user'])]
    public ?float $totalWeight = null;

    #[Groups(['guest-user'])]
    public ?float $subtotal = null;  // ✅ AJOUTÉ

    #[Groups(['guest-user'])]
    public ?float $discountAmount = null;  // ✅ AJOUTÉ

    #[Groups(['guest-user'])]
    public ?float $shippingCost = null;  // ✅ AJOUTÉ

    #[Groups(['guest-user'])]
    public ?float $totalPrice = null;

    #[Groups(['guest-user'])]
    public ?string $promoCode = null;  // ✅ AJOUTÉ
}