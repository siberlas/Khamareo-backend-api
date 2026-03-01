<?php

namespace App\Shipping\Controller;

use App\Shipping\Service\ShippingOptionsResolver;
use App\Cart\Service\CartWeightCalculator;
use App\Cart\Repository\CartRepository;
use App\Catalog\Repository\ProductRepository;
use App\User\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Psr\Log\LoggerInterface;

/**
 * API pour récupérer les options de livraison disponibles
 */
#[AsController]
class ShippingOptionsController extends AbstractController
{
    public function __construct(
        private ShippingOptionsResolver $optionsResolver,
        private CartWeightCalculator $weightCalculator,
        private CartRepository $cartRepository,
        private ProductRepository $productRepository,
        private Security $security,
        private LoggerInterface $shippingLogger,
    ) {}

    /**
     * GET /api/shipping/options
     * 
     * Query params:
     * - country: Code pays (FR, GP, BE, etc.)
     * - postalCode: Code postal (optionnel, pour déduire DOM-TOM)
     * - guestToken: Token invité (si pas connecté)
     */
    #[Route('/api/shipping/options', name: 'shipping_options', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $this->shippingLogger->info('=== SHIPPING OPTIONS ENDPOINT ===');
        
        // 1. Récupérer les paramètres
        $country = $request->query->get('country');
        $postalCode = $request->query->get('postalCode');
        $guestToken = $request->query->get('guestToken');
        
        $this->shippingLogger->info('Paramètres reçus', [
            'country' => $country,
            'postalCode' => $postalCode,
            'guestToken' => $guestToken ? '***' : null,
        ]);

        // 2. Résoudre le code pays
        $countryCode = $this->optionsResolver->resolveCountryCode($country, $postalCode);
        $this->shippingLogger->info('Pays résolu', ['countryCode' => $countryCode]);

        // 3. Charger le panier pour calculer le poids
        $user = $this->security->getUser();
        $userId = $user instanceof User ? $user->getId() : null;
        $this->shippingLogger->info('Utilisateur', ['isConnected' => $user !== null, 'userId' => $userId]);
        
        $cart = null;

        if ($user) {
            $cart = $this->cartRepository->findOneBy(['owner' => $user, 'isActive' => true]);
            $this->shippingLogger->info('Recherche panier utilisateur', ['cartFound' => $cart !== null]);
        } elseif ($guestToken) {
            $cart = $this->cartRepository->findOneBy(['guestToken' => $guestToken, 'isActive' => true]);
            $this->shippingLogger->info('Recherche panier invité', ['cartFound' => $cart !== null]);
        }

        if (!$cart) {
            $this->shippingLogger->error('Panier introuvable', ['user' => $user !== null, 'hasGuestToken' => $guestToken !== null]);
            throw new BadRequestException('Panier introuvable');
        }

        // 4. Calculer le poids total en grammes
        $weightGrams = $this->weightCalculator->getTotalWeightFromCartInGrams($cart);
        $weightKg = round($weightGrams / 1000, 3);
        $this->shippingLogger->info('Poids calculé', ['weightGrams' => $weightGrams, 'weightKg' => $weightKg]);
        
        // 5. Résoudre les options disponibles
        $this->shippingLogger->info('Récupération des options disponibles', ['country' => $countryCode, 'weightGrams' => $weightGrams]);
        $options = $this->optionsResolver->getAvailableOptions($countryCode, $weightGrams);
        $this->shippingLogger->info('Options disponibles', ['count' => count($options)]);

        // 6. Retourner les options
        $response = [
            'country' => $countryCode,
            'weightGrams' => $weightGrams,
            'weightKg' => $weightKg,
            'options' => $options,
        ];
        
        $this->shippingLogger->info('Réponse envoyée', ['optionsCount' => count($options)]);
        
        return $this->json($response);
    }
}