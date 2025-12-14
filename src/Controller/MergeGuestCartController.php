<?php

namespace App\Controller;

use App\Entity\Cart;
use App\Repository\CartRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
class MergeGuestCartController
{
    public function __construct(
        private Security $security,
        private CartRepository $cartRepository,
        private EntityManagerInterface $em
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        $guestToken = $request->query->get('guestToken');

        if (!$guestToken || !$user) {
            return new JsonResponse(['message' => 'No merge needed'], 200);
        }

        // Panier de l'invité
        $guestCart = $this->cartRepository->findOneBy([
            'guestToken' => $guestToken,
            'isActive' => true
        ]);

        if (!$guestCart) {
            return new JsonResponse(['message' => 'Guest cart empty'], 200);
        }

        // Panier de l'utilisateur
        $userCart = $this->cartRepository->findOneBy([
            'owner' => $user,
            'isActive' => true
        ]);

        // Si l'utilisateur n'a PAS encore de panier → on transforme le guest cart en user cart
        if (!$userCart) {
            $guestCart->setOwner($user);
            $guestCart->setGuestToken(null);
            $this->em->flush();
            return new JsonResponse(['message' => 'Cart attached to user'], 200);
        }

        // Sinon → fusionner les items
        foreach ($guestCart->getItems() as $guestItem) {

            $existingItem = $userCart->getItems()->filter(function ($item) use ($guestItem) {
                return $item->getProduct()->getId() === $guestItem->getProduct()->getId();
            })->first();

            if ($existingItem) {
                $existingItem->setQuantity($existingItem->getQuantity() + $guestItem->getQuantity());
            } else {
                $userCart->addItem($guestItem);
                $guestItem->setCart($userCart);
            }
        }

        // Désactiver l'ancien panier invité
        $guestCart->setIsActive(false);

        $this->em->flush();

        return new JsonResponse(['message' => 'Merge completed'], 200);
    }
}
