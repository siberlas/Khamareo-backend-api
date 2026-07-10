<?php

namespace App\Admin\Controller\Cart;

use App\Cart\Entity\Cart;
use App\Shared\Service\ClientContextResolver;
use App\Shared\Service\MailerService;
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
        private LoggerInterface $logger,
        private MailerService $mailerService,
        private ClientContextResolver $clientContext,
    ) {}

    /**
     * Liste paginée des paniers abandonnés (actifs avec au moins un article)
     *
     * GET /api/admin/carts/abandoned?page=1&itemsPerPage=20
     */
    #[Route('/abandoned', name: 'abandoned', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $page         = max(1, $request->query->getInt('page', 1));
            $itemsPerPage = min(100, max(1, $request->query->getInt('itemsPerPage', 20)));

            // Étape 1 : IDs paginés SANS jointure sur items (un INNER JOIN + LIMIT
            // tronquerait/dupliquerait les résultats dès qu'un panier a plusieurs articles)
            $idQb = $this->em->createQueryBuilder()
                ->select('c.id')
                ->from(Cart::class, 'c')
                ->innerJoin('c.items', 'ci')
                ->where('c.isActive = true')
                ->groupBy('c.id')
                ->orderBy('MAX(c.createdAt)', 'DESC');

            $total = count((clone $idQb)->getQuery()->getResult());

            $pagedIds = array_column(
                $idQb->setFirstResult(($page - 1) * $itemsPerPage)
                    ->setMaxResults($itemsPerPage)
                    ->getQuery()
                    ->getResult(),
                'id'
            );

            // Étape 2 : charger les paniers complets (avec items/produits) pour ces IDs uniquement
            $carts = [];
            if (!empty($pagedIds)) {
                $carts = $this->em->createQueryBuilder()
                    ->select('c', 'u', 'ci', 'p')
                    ->from(Cart::class, 'c')
                    ->innerJoin('c.items', 'ci')
                    ->leftJoin('c.owner', 'u')
                    ->leftJoin('ci.product', 'p')
                    ->where('c.id IN (:ids)')
                    ->setParameter('ids', $pagedIds)
                    ->orderBy('c.createdAt', 'DESC')
                    ->getQuery()
                    ->getResult();
            }

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

                $lastReminderAt = $owner && $owner->isGuest()
                    ? $cart->getLastGuestReminderAt()
                    : $cart->getLastReminderAt();
                $reminderCount = $owner && $owner->isGuest()
                    ? $cart->getGuestReminderCount()
                    : $cart->getReminderCount();

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
                    'guestCountry' => $country ?? $cart->getGuestCountry(),
                    'guestReferrer' => $cart->getGuestReferrer(),
                    'source' => $this->clientContext->resolveSource($cart->getGuestReferrer()),
                    'osName' => $cart->getOsName(),
                    'deviceType' => $cart->getDeviceType(),
                    'paymentIntentStarted' => $cart->getPaymentIntentId() !== null,
                    'paymentLastError'     => $cart->getPaymentLastError(),
                    // Une relance ne peut être envoyée que si un email est disponible
                    // (un invité qui n'a jamais atteint l'étape adresse n'a pas d'owner/email)
                    'emailSendable'    => $owner?->getEmail() !== null,
                    'lastReminderAt'   => $lastReminderAt?->format(\DateTime::ATOM),
                    'reminderCount'    => $reminderCount,
                ];
            }

            return $this->json([
                'success'      => true,
                'carts'        => $data,
                'page'         => $page,
                'itemsPerPage' => $itemsPerPage,
                'total'        => $total,
                'totalPages'   => (int) ceil($total / $itemsPerPage),
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

    /**
     * Envoie l'email de relance "problème technique résolu" pour les paniers
     * sélectionnés depuis la page admin (cases cochées / "tout cocher").
     *
     * POST /api/admin/carts/send-reminder
     * Body: { "cartIds": ["uuid1", "uuid2", ...] }
     */
    #[Route('/send-reminder', name: 'send_reminder', methods: ['POST'])]
    public function sendReminder(Request $request): JsonResponse
    {
        $cartIds = json_decode($request->getContent(), true)['cartIds'] ?? [];

        if (!is_array($cartIds) || empty($cartIds)) {
            return $this->json([
                'success' => false,
                'error' => 'cartIds requis (tableau non vide).',
            ], 400);
        }

        $results = [];
        foreach ($cartIds as $cartId) {
            $cart = $this->em->getRepository(Cart::class)->find($cartId);

            if (!$cart) {
                $results[] = ['cartId' => $cartId, 'success' => false, 'error' => 'cart_not_found'];
                continue;
            }

            $email = $cart->getOwner()?->getEmail();
            if (!$email) {
                $results[] = ['cartId' => $cartId, 'success' => false, 'error' => 'no_email_available'];
                continue;
            }

            $sent = $this->mailerService->sendAbandonedCartCheckoutRecovery($cart);
            $results[] = [
                'cartId'  => $cartId,
                'email'   => $email,
                'success' => $sent,
                'error'   => $sent ? null : 'send_failed',
            ];
        }

        $successCount = count(array_filter($results, fn($r) => $r['success']));

        return $this->json([
            'success'      => true,
            'sentCount'    => $successCount,
            'failedCount'  => count($results) - $successCount,
            'results'      => $results,
        ]);
    }
}
