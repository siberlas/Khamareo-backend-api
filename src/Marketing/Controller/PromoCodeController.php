<?php

namespace App\Marketing\Controller;

use App\Marketing\Entity\PromoCodeRecipient;
use App\Marketing\Entity\PromoCodeRedemption;
use App\Marketing\Repository\PromoCodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[Route('/api')]
#[AsController]
class PromoCodeController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    #[Route('/promo-codes/validate', name: 'validate_promo_code', methods: ['POST'])]
    public function validatePromoCode(
        Request $request,
        PromoCodeRepository $promoCodeRepository
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $code    = $data['code']    ?? null;
        $email   = $data['email']   ?? null;
        $country = $data['country'] ?? null;
        $locale  = $data['locale']  ?? null;
        $channel = $data['channel'] ?? null;

        if (!$code) {
            return $this->json(['valid' => false, 'message' => 'Code promo manquant'], 400);
        }

        $promoCode = $promoCodeRepository->findOneBy(['code' => strtoupper($code)]);
        if (!$promoCode) {
            return $this->json(['valid' => false, 'message' => 'Code promo invalide'], 404);
        }

        // Code pas encore démarré
        if ($promoCode->getStartsAt() && $promoCode->getStartsAt() > new \DateTimeImmutable()) {
            return $this->json([
                'valid'   => false,
                'message' => 'Ce code promo n\'est pas encore actif',
            ], 400);
        }

        // Restriction email directe (code 1-to-1)
        if ($promoCode->getEmail()) {
            if ($email && strtolower($promoCode->getEmail()) !== strtolower($email)) {
                return $this->json([
                    'valid'   => false,
                    'message' => 'Ce code promo n\'est pas valide pour votre adresse email',
                ], 403);
            }
            // Quand la restriction email directe est définie, la liste des destinataires est ignorée
        } elseif ($email) {
            // Restriction liste de destinataires : si le code a des recipients, l'email doit y figurer
            $recipientCount = (int) $this->em->createQuery(
                'SELECT COUNT(r.id) FROM App\Marketing\Entity\PromoCodeRecipient r WHERE r.promoCode = :promo'
            )->setParameter('promo', $promoCode)->getSingleScalarResult();

            if ($recipientCount > 0) {
                $isRecipient = (int) $this->em->createQuery(
                    'SELECT COUNT(r.id) FROM App\Marketing\Entity\PromoCodeRecipient r WHERE r.promoCode = :promo AND LOWER(r.email) = LOWER(:email)'
                )->setParameter('promo', $promoCode)->setParameter('email', $email)->getSingleScalarResult();

                if (!$isRecipient) {
                    return $this->json([
                        'valid'   => false,
                        'message' => 'Ce code promo n\'est pas valide pour votre adresse email',
                    ], 403);
                }
            }
        }

        // Restriction pays
        if ($country && $promoCode->getAllowedCountries() && count($promoCode->getAllowedCountries()) > 0) {
            if (!in_array($country, $promoCode->getAllowedCountries(), true)) {
                return $this->json([
                    'valid'   => false,
                    'message' => 'Ce code promo n\'est pas disponible dans votre pays',
                ], 403);
            }
        }

        // Restriction canal
        if ($channel && $promoCode->getAllowedChannels() && count($promoCode->getAllowedChannels()) > 0) {
            if (!in_array($channel, $promoCode->getAllowedChannels(), true)) {
                return $this->json([
                    'valid'   => false,
                    'message' => 'Ce code promo n\'est pas disponible sur ce canal',
                ], 403);
            }
        }

        // Restriction locale
        if ($locale && $promoCode->getAllowedLocales() && count($promoCode->getAllowedLocales()) > 0) {
            if (!in_array($locale, $promoCode->getAllowedLocales(), true)) {
                return $this->json([
                    'valid'   => false,
                    'message' => 'Ce code promo n\'est pas disponible dans votre langue',
                ], 403);
            }
        }

        // Restriction audience (guest / registered)
        $customerType = $data['customerType'] ?? null;
        $eligibleCustomer = $promoCode->getEligibleCustomer();
        if ($customerType && !in_array($eligibleCustomer, ['all', 'both'], true)) {
            if ($eligibleCustomer === 'registered' && $customerType !== 'registered') {
                return $this->json([
                    'valid'   => false,
                    'message' => 'Ce code promo est réservé aux clients inscrits',
                ], 403);
            }
            if ($eligibleCustomer === 'guest' && $customerType !== 'guest') {
                return $this->json([
                    'valid'   => false,
                    'message' => 'Ce code promo est réservé aux clients non inscrits',
                ], 403);
            }
        }

        // Validité de base (actif, non expiré, non utilisé)
        if (!$promoCode->isValid()) {
            if ($promoCode->isUsed()) {
                $message = 'Ce code promo a déjà été utilisé';
            } elseif ($promoCode->getExpiresAt() !== null && $promoCode->getExpiresAt() < new \DateTimeImmutable()) {
                $message = 'Ce code promo a expiré le ' . $promoCode->getExpiresAt()->format('d/m/Y');
            } else {
                // Code inactif ou autre raison : message générique (ne pas révéler qu'il est désactivé)
                $message = 'Ce code promo n\'est pas valide';
            }
            return $this->json(['valid' => false, 'message' => $message], 400);
        }

        // Limite d'utilisations globale
        if ($promoCode->getMaxUses() !== null) {
            $usageCount = (int) $this->em->createQuery(
                'SELECT COUNT(r.id) FROM App\Marketing\Entity\PromoCodeRedemption r WHERE r.promoCode = :promo'
            )->setParameter('promo', $promoCode)->getSingleScalarResult();

            if ($usageCount >= $promoCode->getMaxUses()) {
                return $this->json([
                    'valid'   => false,
                    'message' => 'Ce code promo a atteint sa limite d\'utilisation',
                ], 400);
            }
        }

        // Limite d'utilisations par email
        if ($promoCode->getMaxUsesPerEmail() !== null && $email) {
            $perEmailCount = (int) $this->em->createQuery(
                'SELECT COUNT(r.id) FROM App\Marketing\Entity\PromoCodeRedemption r WHERE r.promoCode = :promo AND r.email = :email'
            )->setParameter('promo', $promoCode)->setParameter('email', $email)->getSingleScalarResult();

            if ($perEmailCount >= $promoCode->getMaxUsesPerEmail()) {
                return $this->json([
                    'valid'   => false,
                    'message' => 'Vous avez déjà utilisé ce code promo',
                ], 400);
            }
        }

        return $this->json([
            'valid'     => true,
            'message'   => 'Code promo valide',
            'promoCode' => [
                'code'               => $promoCode->getCode(),
                'discountPercentage' => $promoCode->getDiscountPercentage(),
                'discountAmount'     => $promoCode->getDiscountAmount(),
                'expiresAt'          => $promoCode->getExpiresAt()->format('c'),
                'type'               => $promoCode->getType(),
                'minOrderAmount'     => $promoCode->getMinOrderAmount(),
                'stackable'          => $promoCode->isStackable(),
            ],
        ]);
    }
}
