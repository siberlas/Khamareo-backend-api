<?php

namespace App\Cart\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Cart\Entity\CartItem;
use App\Marketing\Service\PromoCodeApplicationService;
use Symfony\Bundle\SecurityBundle\Security;
use ApiPlatform\Metadata\Exception\AccessDeniedException;

final class CartItemProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private PromoCodeApplicationService $promoService,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = [])
    {
        /** @var CartItem $data */
        $cart = $data->getCart();
        $product = $data->getProduct();
        $user = $this->security->getUser();

        // ✅ Vérification propriétaire
        if ($user) {
            if ($cart->getOwner() !== $user) {
                throw new AccessDeniedException("This cart does not belong to the current user.");
            }
        } else {
            if (!$cart->getGuestToken()) {
                throw new AccessDeniedException("Guest cart missing token.");
            }
        }

        // ✅ Recherche item existant
        $existingItem = $this->em->getRepository(CartItem::class)
            ->findOneBy(['cart' => $cart, 'product' => $product]);

        if ($existingItem) {
            $existingItem->setQuantity($existingItem->getQuantity() + $data->getQuantity());
            $this->em->flush();
            $this->recalculatePromos($cart);
            return $existingItem;
        }

        $this->em->persist($data);
        $this->em->flush();
        $this->recalculatePromos($cart);

        return $data;
    }

    private function recalculatePromos(\App\Cart\Entity\Cart $cart): void
    {
        if (!empty($cart->getPromoCodesData())) {
            $changed = $this->promoService->recalculatePercentageDiscounts($cart);
            if ($changed) {
                $this->em->flush();
            }
        }
    }
}
