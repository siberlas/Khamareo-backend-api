<?php

namespace App\Shipping\Controller;

use App\Shipping\Service\AddressValidationService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
class AddressValidationController extends AbstractController
{
    public function __construct(
        private AddressValidationService $validator,
        private LoggerInterface $logger,
    ) {}

    /**
     * POST /api/address/validate
     * Valide une adresse avant sauvegarde
     * 
     * Body:
     * {
     *   "street": "123 Rue de la Paix",
     *   "postalCode": "75000",
     *   "city": "Paris",
     *   "country": "FR",
     *   "lat": 48.8566,
     *   "lon": 2.3522
     * }
     */
    #[Route('/api/address/validate', name: 'address_validate', methods: ['POST'])]
    public function validate(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $street = (string) ($data['street'] ?? '');
        $postalCode = (string) ($data['postalCode'] ?? '');
        $city = (string) ($data['city'] ?? '');
        $country = (string) ($data['country'] ?? 'FR');
        $lat = isset($data['lat']) ? (float) $data['lat'] : null;
        $lon = isset($data['lon']) ? (float) $data['lon'] : null;
        $strict = array_key_exists('strict', $data) ? (bool) $data['strict'] : null;

        if (!$street || !$postalCode || !$city) {
            return $this->json([
                'valid' => false,
                'message' => 'Tous les champs sont obligatoires (street, postalCode, city)',
            ], 400);
        }

        $this->logger->info('Address validation request', [
            'street' => $street,
            'city' => $city,
            'country' => $country,
        ]);

        $result = $this->validator->validateAddress($street, $postalCode, $city, $country, $lat, $lon, $strict);

        return $this->json($result, $result['valid'] ? 200 : 422);
    }
}
