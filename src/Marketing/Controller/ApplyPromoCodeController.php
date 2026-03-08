<?php
// src/Controller/ApplyPromoCodeController.php

namespace App\Marketing\Controller;

use App\Cart\Entity\Cart;
use App\Cart\Repository\CartRepository;
use App\Marketing\Repository\PromoCodeRepository;
use App\Marketing\Service\PromoCodeApplicationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Doctrine\ORM\EntityManagerInterface;  

#[Route('/api')]
#[AsController]
class ApplyPromoCodeController extends AbstractController
{
    public function __construct(
        private CartRepository $cartRepository,
        private PromoCodeRepository $promoCodeRepository,
        private PromoCodeApplicationService $promoCodeService,
        private Security $security,
        private EntityManagerInterface $em

    ) {}

    #[Route('/cart/apply-promo', name: 'apply_promo_to_cart', methods: ['POST'])]
    public function applyPromo(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $code = $data['code'] ?? null;
        $guestToken = $data['guestToken'] ?? null;
        $emailFromBody = $data['email'] ?? null;

        if (!$code) {
            return $this->json(['error' => 'Code promo manquant'], 400);
        }

        // Récupérer le panier (utilisateur connecté ou invité)
        $user = $this->security->getUser();
        
        if ($user) {
            $cart = $this->cartRepository->findOneBy([
                'owner' => $user,
                'isActive' => true
            ]);
        } elseif ($guestToken) {
            $cart = $this->cartRepository->findOneBy([
                'guestToken' => $guestToken,
                'isActive' => true
            ]);
        } else {
            return $this->json(['error' => 'Panier introuvable'], 404);
        }

        if (!$cart) {
            return $this->json(['error' => 'Panier introuvable'], 404);
        }

        // Vérifier le code promo
        $promoCode = $this->promoCodeRepository->findOneBy([
            'code' => strtoupper($code)
        ]);

        if (!$promoCode) {
            return $this->json(['error' => 'Code promo invalide'], 404);
        }

        // Résoudre l'email (connecté > invité depuis body)
        $email = $user ? $user->getEmail() : $emailFromBody;

        // Vérifier l'email si nécessaire (seulement si le code a une restriction email)
        if ($promoCode->getEmail() && $email && strtolower($promoCode->getEmail()) !== strtolower($email)) {
            return $this->json([
                'error' => 'Ce code promo n\'est pas valide pour votre adresse email',
            ], 403);
        }

        // Vérifier la restriction d'audience
        $eligibleCustomer = $promoCode->getEligibleCustomer();
        $isGuest = $user === null;
        if (!in_array($eligibleCustomer, ['all', 'both'], true)) {
            if ($eligibleCustomer === 'registered' && $isGuest) {
                return $this->json(['error' => 'Ce code promo est réservé aux clients inscrits'], 403);
            }
            if ($eligibleCustomer === 'guest' && !$isGuest) {
                return $this->json(['error' => 'Ce code promo est réservé aux clients non inscrits'], 403);
            }
        }

        if (!$promoCode->isValid()) {
            return $this->json(['error' => 'Ce code promo n\'est plus valide'], 400);
        }

        // Vérifier la limite d'utilisations par email (1 par défaut)
        if ($email) {
            $maxPerEmail = $promoCode->getMaxUsesPerEmail() ?? 1;
            $perEmailCount = (int) $this->em->createQuery(
                'SELECT COUNT(r.id) FROM App\Marketing\Entity\PromoCodeRedemption r WHERE r.promoCode = :promo AND LOWER(r.email) = :email'
            )->setParameter('promo', $promoCode)->setParameter('email', strtolower($email))->getSingleScalarResult();

            if ($perEmailCount >= $maxPerEmail) {
                return $this->json(['error' => 'Vous avez déjà utilisé ce code promo'], 400);
            }
        }

        // Appliquer le code promo
        try {
            $this->promoCodeService->applyToCart($cart, $promoCode, $email);

            return $this->json([
                'success' => true,
                'message' => 'Code promo appliqué avec succès',
                'cart' => [
                    'promoCode'      => $cart->getPromoCode(),
                    'promoCodes'     => $cart->getPromoCodes(),
                    'promoCodesData' => $cart->getPromoCodesData() ?? [],
                    'discountAmount' => $cart->getDiscountAmount(),
                    'totalAmount'    => $cart->getTotalAmount(),
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/cart/remove-promo', name: 'remove_promo_from_cart', methods: ['POST'])]
    public function removePromo(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $guestToken    = $data['guestToken'] ?? null;
        $codeToRemove  = $data['code'] ?? null;

        $user = $this->security->getUser();

        if ($user) {
            $cart = $this->cartRepository->findOneBy(['owner' => $user, 'isActive' => true]);
        } elseif ($guestToken) {
            $cart = $this->cartRepository->findOneBy(['guestToken' => $guestToken, 'isActive' => true]);
        } else {
            return $this->json(['error' => 'Panier introuvable'], 404);
        }

        if (!$cart) {
            return $this->json(['error' => 'Panier introuvable'], 404);
        }

        $this->promoCodeService->removeFromCart($cart, $codeToRemove);

        return $this->json([
            'success'        => true,
            'message'        => $codeToRemove ? "Code $codeToRemove retiré" : 'Codes promo retirés',
            'cart' => [
                'promoCode'      => $cart->getPromoCode(),
                'promoCodes'     => $cart->getPromoCodes(),
                'promoCodesData' => $cart->getPromoCodesData() ?? [],
                'discountAmount' => $cart->getDiscountAmount(),
                'totalAmount'    => $cart->getTotalAmount(),
            ]
        ]);
    }
}