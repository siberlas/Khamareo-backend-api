<?php

namespace App\Shared\Voter;

use App\User\Entity\Address;
use App\Order\Entity\Order;
use App\User\Entity\User;
use App\Order\Repository\OrderRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class AddressOrderVoter extends Voter
{
    public const VIEW = 'ADDRESS_VIEW';

    public function __construct(
        private OrderRepository $orderRepository
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::VIEW && $subject instanceof Address;
    }

    protected function voteOnAttribute(string $attribute, mixed $address, TokenInterface $token): bool
    {
        $user = $token->getUser();

        // Récupérer la commande liée
        $order = $this->findOrderFromAddress($address);

        if (!$order) {
            return false;
        }

        // ADMIN → OK
        if ($user instanceof User && in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        // USER CONNECTÉ → la commande lui appartient
        if ($user instanceof User && $order->getOwner()?->getId() === $user->getId()) {
            return true;
        }

        // INVITÉ → commandes invitées uniquement
        if (!$user instanceof User && $order->getGuestEmail()) {
            return true;
        }

        return false;
    }


    private function findOrderFromAddress(Address $address): ?Order
    {
        return $this->orderRepository->findOneBy(['shippingAddress' => $address])
            ?? $this->orderRepository->findOneBy(['billingAddress' => $address]);
    }

    private function isGuestAllowed(Address $address): bool
    {
        $order = $this->findOrderFromAddress($address);

        return $order !== null && $order->getGuestEmail() !== null;
    }
}
