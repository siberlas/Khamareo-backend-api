<?php

namespace App\Order\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Order\Dto\GuestCheckoutView;
use App\Cart\Entity\Cart;
use App\Cart\Service\CartWeightCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Psr\Log\LoggerInterface;

final class GuestCheckoutProvider implements ProviderInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private CartWeightCalculator $weightCalculator,
        private LoggerInterface $logger
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?GuestCheckoutView
    {
        $guestToken = $uriVariables['guestToken'] ?? null;
        
        $this->logger->info('🔍 GuestCheckoutProvider called', [
            'guestToken' => $guestToken,
            'uriVariables' => $uriVariables,
        ]);

        if (!$guestToken) {
            throw new BadRequestException("Le token invité est requis.");
        }

        // Récupérer le panier
        $cart = $this->em->getRepository(Cart::class)->findOneBy([
            'guestToken' => $guestToken,
            'isActive' => true
        ]);

        if (!$cart) {
            $this->logger->warning('❌ Cart not found', [
                'guestToken' => $guestToken
            ]);
            throw new BadRequestException("Aucun panier trouvé pour ce token invité.");
        }

        $this->logger->info('✅ Cart found', [
            'cart_id' => $cart->getId(),
            'items_count' => $cart->getItems()->count(),
        ]);

        // Récupérer l'utilisateur (peut être null si invité pur)
        $user = $cart->getOwner();

        // Créer la vue
        $view = new GuestCheckoutView();
        $view->guestToken = $guestToken;
        $view->userEmail = $user?->getEmail();
        $view->firstName = $user?->getFirstName();
        $view->lastName = $user?->getLastName();
        $view->phone = $user?->getPhone();
        
        // ❌ SUPPRIMER : Cart n'a pas de shippingAddress
        // $address = $cart->getShippingAddress();
        
        // Pour l'instant, pas d'adresse pré-remplie
        // L'adresse sera fournie lors du checkout
        $view->address = null;

        // Détails du panier
        $items = [];
        foreach ($cart->getItems() as $item) {
            $product = $item->getProduct();
            $items[] = [
                'id' => $item->getId(),
                'product' => $product?->getName(),
                'slug' => $product?->getSlug(),
                'quantity' => $item->getQuantity(),
                'unitPrice' => (float) $item->getUnitPrice(),
                'subtotal' => $item->getQuantity() * (float) $item->getUnitPrice(),
            ];
        }

        $view->cartItems = $items;
        $view->totalWeight = $this->weightCalculator->getTotalWeightFromCart($cart);
        $view->subtotal = $cart->getSubtotal();
        $view->discountAmount = $cart->getDiscountAmount() ? (float) $cart->getDiscountAmount() : 0;
        $view->shippingCost = $cart->getShippingCost() ?? 0;
        $view->totalPrice = $cart->getTotalAmount();
        $view->promoCode = $cart->getPromoCode();

        $this->logger->info('✅ GuestCheckoutView created', [
            'items_count' => count($items),
            'total' => $view->totalPrice,
        ]);

        return $view;
    }
}