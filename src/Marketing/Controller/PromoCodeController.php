<?php
// src/Controller/PromoCodeController.php

namespace App\Marketing\Controller;

use App\Repository\PromoCodeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;


#[Route('/api')]
#[AsController]
class PromoCodeController extends AbstractController
{
    #[Route('/promo-codes/validate', name: 'validate_promo_code', methods: ['POST'])]
    public function validatePromoCode(
        Request $request,
        PromoCodeRepository $promoCodeRepository
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $code = $data['code'] ?? null;
        $email = $data['email'] ?? null;

        if (!$code) {
            return $this->json([
                'valid' => false,
                'message' => 'Code promo manquant'
            ], 400);
        }

        $promoCode = $promoCodeRepository->findOneBy(['code' => strtoupper($code)]);

        if (!$promoCode) {
            return $this->json([
                'valid' => false,
                'message' => 'Code promo invalide'
            ], 404);
        }

        // Vérifier si le code est pour un email spécifique (optionnel si email fourni)
        if ($email && $promoCode->getEmail() !== $email) {
            return $this->json([
                'valid' => false,
                'message' => 'Ce code promo n\'est pas valide pour cet email'
            ], 403);
        }

        // Vérifier si le code est valide
        if (!$promoCode->isValid()) {
            if ($promoCode->isUsed()) {
                $message = 'Ce code promo a déjà été utilisé';
            } elseif ($promoCode->getExpiresAt() < new \DateTimeImmutable()) {
                $message = 'Ce code promo a expiré le ' . $promoCode->getExpiresAt()->format('d/m/Y');
            } elseif (!$promoCode->isActive()) {
                $message = 'Ce code promo n\'est plus actif';
            } else {
                $message = 'Ce code promo n\'est pas valide';
            }

            return $this->json([
                'valid' => false,
                'message' => $message
            ], 400);
        }

        // Code valide, retourner les informations
        return $this->json([
            'valid' => true,
            'message' => 'Code promo valide',
            'promoCode' => [
                'code' => $promoCode->getCode(),
                'discountPercentage' => $promoCode->getDiscountPercentage(),
                'discountAmount' => $promoCode->getDiscountAmount(),
                'expiresAt' => $promoCode->getExpiresAt()->format('c'),
                'type' => $promoCode->getType()
            ]
        ]);
    }
}