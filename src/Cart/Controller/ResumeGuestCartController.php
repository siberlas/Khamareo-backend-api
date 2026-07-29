<?php

namespace App\Cart\Controller;

use App\Cart\Entity\Cart;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Reprise d'une session invité interrompue (ex: relance après échec de
 * paiement) : renvoie l'identité et la dernière adresse saisies, pour que
 * le frontend puisse pré-remplir /checkout/delivery sans tout ressaisir.
 *
 * Accès : le guestToken sert de secret de session — quiconque le possède
 * (lien reçu par email) a le même accès que le propriétaire du panier,
 * exactement comme pour /api/cart?guestToken=... déjà en place.
 */
#[AsController]
class ResumeGuestCartController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/api/cart/resume', name: 'cart_resume', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $guestToken = $request->query->get('guestToken');
        if (!$guestToken) {
            return $this->json(['error' => 'guestToken manquant.'], 400);
        }

        $cart = $this->em->getRepository(Cart::class)->findOneBy([
            'guestToken' => $guestToken,
            'isActive' => true,
        ]);

        if (!$cart || $cart->getItems()->isEmpty()) {
            return $this->json(['error' => 'Panier introuvable ou vide.'], 404);
        }

        $owner = $cart->getOwner();

        $personal = $owner ? [
            'email' => $owner->getEmail(),
            'firstname' => $owner->getFirstName(),
            'lastname' => $owner->getLastName(),
            'phone' => $owner->getPhone(),
        ] : null;

        $address = $cart->getDeliveryAddress();
        $shipping = $address ? [
            'street' => $address->getStreetAddress(),
            'complement' => $address->getAddressComplement(),
            'city' => $address->getCity(),
            'postalCode' => $address->getPostalCode(),
            'state' => $address->getState(),
            'country' => $address->getCountry(),
            'isRelayPoint' => $address->isRelayPoint(),
            'relayPointId' => $address->getRelayPointId(),
            'relayCarrier' => $address->getRelayCarrier(),
        ] : null;

        return $this->json([
            'itemCount' => $cart->getItems()->count(),
            'personal' => $personal,
            'shipping' => $shipping,
        ]);
    }
}
