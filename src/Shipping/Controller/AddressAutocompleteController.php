<?php

namespace App\Shipping\Controller;

use App\Shipping\Service\AdresseApiService;
use App\Shipping\Service\AddressCacheService;
use App\Shipping\Service\MapboxGeocodingService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
class AddressAutocompleteController extends AbstractController
{
    public function __construct(
        private MapboxGeocodingService $mapbox,
        private AdresseApiService $adresse,
        private AddressCacheService $cache,
        private LoggerInterface $logger,
    ) {}

    /**
     * GET /api/address/autocomplete?q=...&country=FR
     */
    #[Route('/api/address/autocomplete', name: 'address_autocomplete', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $q = (string) $request->query->get('q', '');
        $country = $request->query->get('country');
        $limit = (int) $request->query->get('limit', 6);

        if (trim($q) === '') {
            return $this->json(['error' => 'Missing query parameter q'], 400);
        }

        $this->logger->info('Address autocomplete request', ['q' => $q, 'country' => $country, 'limit' => $limit]);

        // If country is France (or not provided), use Adresse API for better French results
        if ($country === null || strtoupper($country) === 'FR') {
            // Check cache first
            $cached = $this->cache->get('adresse', $q, $country);
            if ($cached !== null) {
                return $this->json(['source' => 'adresse.data.gouv.fr', 'results' => $cached, 'cached' => true]);
            }

            $results = $this->adresse->autocomplete($q, $limit);
            $this->cache->set('adresse', $q, $country, $results);
            return $this->json(['source' => 'adresse.data.gouv.fr', 'results' => $results, 'cached' => false]);
        }

        // Otherwise use Mapbox
        // Check cache first
        $cached = $this->cache->get('mapbox', $q, $country);
        if ($cached !== null) {
            return $this->json(['source' => 'mapbox', 'results' => $cached, 'cached' => true]);
        }

        $results = $this->mapbox->autocomplete($q, $country, $limit);
        $this->cache->set('mapbox', $q, $country, $results);
        return $this->json(['source' => 'mapbox', 'results' => $results, 'cached' => false]);
    }

    /**
     * GET /api/address/reverse-geocode?lat=48.8566&lon=2.3522
     * Reverse geocode coordinates to address
     */
    #[Route('/api/address/reverse-geocode', name: 'address_reverse_geocode', methods: ['GET'])]
    public function reverseGeocode(Request $request): JsonResponse
    {
        $lat = (float) $request->query->get('lat');
        $lon = (float) $request->query->get('lon');
        $country = $request->query->get('country');

        if ($lat === 0.0 || $lon === 0.0) {
            return $this->json(['error' => 'Missing or invalid lat/lon parameters'], 400);
        }

        $this->logger->info('Address reverse geocode request', ['lat' => $lat, 'lon' => $lon, 'country' => $country]);

        // Try Adresse API for France first
        if ($country === null || strtoupper($country) === 'FR') {
            $result = $this->adresse->reverseGeocode($lat, $lon);
            if ($result) {
                return $this->json(['source' => 'adresse.data.gouv.fr', 'address' => $result]);
            }
        }

        // Fallback to Mapbox
        $result = $this->mapbox->reverseGeocode($lat, $lon);
        if ($result) {
            return $this->json(['source' => 'mapbox', 'address' => $result]);
        }

        return $this->json(['error' => 'Address not found for coordinates'], 404);
    }
}
