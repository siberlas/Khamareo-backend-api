<?php
// src/Controller/ApplyPromoCodeController.php

namespace App\Controller;

use App\Entity\Cart;
use App\Repository\CartRepository;
use App\Repository\PromoCodeRepository;
use App\Service\PromoCodeApplicationService;
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

        // Vérifier l'email si nécessaire
        $email = $user ? $user->getEmail() : null;
        if ($email && $promoCode->getEmail() !== $email) {
            return $this->json([
                'error' => 'Ce code promo n\'est pas valide pour votre compte'
            ], 403);
        }

        if (!$promoCode->isValid()) {
            return $this->json(['error' => 'Ce code promo n\'est plus valide'], 400);
        }

        // Appliquer le code promo
        try {
            $this->promoCodeService->applyToCart($cart, $promoCode);

            return $this->json([
                'success' => true,
                'message' => 'Code promo appliqué avec succès',
                'cart' => [
                    'promoCode' => $cart->getPromoCode(),
                    'discountAmount' => $cart->getDiscountAmount(),
                    'totalAmount' => $cart->getTotalAmount()
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
        $guestToken = $data['guestToken'] ?? null;

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

        $cart->setPromoCode(null);
        $cart->setDiscountAmount(null);
        
        $this->em->flush();


        return $this->json([
            'success' => true,
            'message' => 'Code promo retiré',
            'cart' => [
                'totalAmount' => $cart->getTotalAmount()
            ]
        ]);
    }
}