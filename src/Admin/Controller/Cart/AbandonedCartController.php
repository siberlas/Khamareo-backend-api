<?php

namespace App\Admin\Controller\Cart;

use App\Cart\Entity\Cart;
use App\Cart\Repository\CartRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
#[Route('/api/admin/carts', name: 'admin_carts_')]
class AbandonedCartController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private CartRepository $cartRepository,
        private LoggerInterface $logger
    ) {}

    /**
     * Liste des paniers abandonnés (actifs avec au moins un article)
     *
     * GET /api/admin/carts/abandoned?limit=50
     */
    #[Route('/abandoned', name: 'abandoned', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $limit = min(200, max(1, $request->query->getInt('limit', 50)));

            $carts = $this->em->createQueryBuilder()
                ->select('c', 'u', 'ci', 'p')
                ->from(Cart::class, 'c')
                ->innerJoin('c.items', 'ci')
                ->leftJoin('c.owner', 'u')
                ->leftJoin('ci.product', 'p')
                ->where('c.isActive = true')
                ->orderBy('c.createdAt', 'DESC')
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult();

            $data = [];
            foreach ($carts as $cart) {
                $owner = $cart->getOwner();

                $items = [];
                $subtotal = 0.0;
                foreach ($cart->getItems() as $item) {
                    $product = $item->getProduct();
                    $lineTotal = $item->getQuantity() * $item->getUnitPrice();
                    $subtotal += $lineTotal;
                    $items[] = [
                        'productName' => $product?->getName() ?? 'Produit inconnu',
                        'productSlug' => $product?->getSlug(),
                        'quantity' => $item->getQuantity(),
                        'unitPrice' => $item->getUnitPrice(),
                        'lineTotal' => round($lineTotal, 2),
                    ];
                }

                // Country from owner's default personal address
                $country = null;
                $city = null;
                if ($owner) {
                    foreach ($owner->getAddresses() as $address) {
                        if ($address->isDefault() && $address->getAddressKind() === 'personal') {
                            $country = $address->getCountry();
                            $city = $address->getCity();
                            break;
                        }
                    }
                    if ($country === null) {
                        foreach ($owner->getAddresses() as $address) {
                            if ($address->getCountry()) {
                                $country = $address->getCountry();
                                $city = $address->getCity();
                                break;
                            }
                        }
                    }
                }

                $data[] = [
                    'id' => $cart->getId()->toRfc4122(),
                    'guestToken' => $cart->getGuestToken(),
                    'createdAt' => $cart->getCreatedAt()?->format(\DateTime::ATOM),
                    'updatedAt' => $cart->getUpdatedAt()?->format(\DateTime::ATOM),
                    'subtotal' => round($subtotal, 2),
                    'shippingCost' => $cart->getShippingCost(),
                    'itemsCount' => count($items),
                    'items' => $items,
                    'customer' => $owner ? [
                        'email' => $owner->getEmail(),
                        'firstName' => $owner->getFirstName(),
                        'lastName' => $owner->getLastName(),
                        'fullName' => trim($owner->getFirstName() . ' ' . $owner->getLastName()),
                        'isGuest' => $owner->isGuest(),
                        'country' => $country,
                        'city' => $city,
                    ] : null,
                    'paymentIntentStarted' => $cart->getPaymentIntentId() !== null,
                ];
            }

            return $this->json([
                'success' => true,
                'carts' => $data,
                'total' => count($data),
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Get abandoned carts failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la récupération des paniers abandonnés',
            ], 500);
        }
    }
}
