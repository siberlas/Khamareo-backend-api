<?php

namespace App\User\Controller;
use Symfony\Component\HttpKernel\Attribute\AsController;

use App\Order\Repository\OrderRepository;
use App\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * RGPD – Droits des personnes concernées (Articles 17 & 20 du RGPD)
 *
 * GET  /api/users/me/export     → Export de toutes les données personnelles (Art. 20)
 * POST /api/users/me/anonymize  → Anonymisation du compte (droit à l'effacement Art. 17)
 */
#[Route('/api/users/me')]
#[IsGranted('ROLE_USER')]
#[AsController]
class GdprController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private OrderRepository        $orderRepository,
    ) {}

    /**
     * Export données personnelles – Article 20 RGPD (portabilité).
     * Retourne un JSON structuré avec toutes les données de l'utilisateur.
     */
    #[Route('/export', name: 'gdpr_export', methods: ['GET'])]
    public function export(#[CurrentUser] User $user): JsonResponse
    {
        $orders = [];
        foreach ($this->orderRepository->findBy(['owner' => $user], ['createdAt' => 'DESC']) as $order) {
            $items = [];
            foreach ($order->getItems() as $item) {
                $items[] = [
                    'product'   => $item->getProduct()?->getName(),
                    'quantity'  => $item->getQuantity(),
                    'unitPrice' => $item->getUnitPrice(),
                ];
            }
            $orders[] = [
                'orderNumber' => $order->getOrderNumber(),
                'status'      => $order->getStatus(),
                'total'       => $order->getTotalAmount(),
                'currency'    => $order->getCurrency(),
                'createdAt'   => $order->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'items'       => $items,
            ];
        }

        $addresses = [];
        foreach ($user->getAddresses() as $address) {
            $addresses[] = [
                'label'         => $address->getLabel(),
                'streetAddress' => $address->getStreetAddress(),
                'postalCode'    => $address->getPostalCode(),
                'city'          => $address->getCity(),
                'country'       => $address->getCountry(),
            ];
        }

        $data = [
            'exportedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'subject'    => 'Données personnelles – Khamareo',
            'legalBasis' => 'Article 20 RGPD – Droit à la portabilité',
            'profile'    => [
                'email'               => $user->getEmail(),
                'firstName'           => $user->getFirstName(),
                'lastName'            => $user->getLastName(),
                'phone'               => $user->getPhone(),
                'createdAt'           => $user->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'newsletter'          => $user->isNewsletter(),
                'newsletterConsentAt' => $user->getNewsletterConsentAt()?->format(\DateTimeInterface::ATOM),
                'acceptTerms'         => $user->getAcceptTerms(),
                'acceptTermsAt'       => $user->getAcceptTermsAt()?->format(\DateTimeInterface::ATOM),
                'isVerified'          => $user->isVerified(),
            ],
            'addresses'  => $addresses,
            'orders'     => $orders,
        ];

        return new JsonResponse($data, Response::HTTP_OK, [
            'Content-Disposition' => 'attachment; filename="mes-donnees-khamareo.json"',
        ]);
    }

    /**
     * Anonymisation du compte – Article 17 RGPD (droit à l'effacement).
     *
     * Les données personnelles sont anonymisées plutôt que supprimées pour
     * conserver l'historique comptable des commandes (obligation légale 10 ans).
     */
    #[Route('/anonymize', name: 'gdpr_anonymize', methods: ['POST'])]
    public function anonymize(#[CurrentUser] User $user): JsonResponse
    {
        $anonymizedId = substr(bin2hex(random_bytes(8)), 0, 12);

        $user->setEmail("deleted-{$anonymizedId}@anonymized.invalid");
        $user->setFirstName('Utilisateur');
        $user->setLastName('Supprimé');
        $user->setPhone(null);
        $user->setAddress(null);
        $user->setPassword(bin2hex(random_bytes(32))); // invalide le mot de passe
        $user->setNewsletter(false);
        $user->setRoles([]);
        $user->setStripeCustomerId(null);

        foreach ($user->getAddresses() as $address) {
            $this->em->remove($address);
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Votre compte a été anonymisé conformément au RGPD (Art. 17). '
                       . "L'historique de commandes est conservé à des fins comptables légales.",
        ]);
    }
}
