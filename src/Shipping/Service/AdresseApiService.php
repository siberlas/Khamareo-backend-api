<?php

namespace App\Shipping\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AdresseApiService
{
    public function __construct(
        private HttpClientInterface $http,
        private LoggerInterface $logger,
    ) {}

    /**
     * Autocomplete via data.gouv.fr API
     * Returns normalized suggestions
     */
    public function autocomplete(string $query, int $limit = 5): array
    {
        $url = 'https://api-adresse.data.gouv.fr/search/';
        $params = [
            'q' => $query,
            'limit' => $limit,
        ];

        try {
            $response = $this->http->request('GET', $url, ['query' => $params]);
            $data = $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('Adresse API request failed', ['exception' => $e->getMessage(), 'query' => $query]);
            return [];
        }

        $results = [];
        foreach ($data['features'] ?? [] as $feature) {
            $props = $feature['properties'] ?? [];
            $geom = $feature['geometry'] ?? null;
            $coords = $geom['coordinates'] ?? null;

            $results[] = [
                'id' => $feature['id'] ?? null,
                'label' => $props['label'] ?? ($props['name'] ?? ''),
                'postcode' => $props['postcode'] ?? null,
                'postalCode' => $props['postcode'] ?? null,
                'city' => $props['city'] ?? null,
                'lat' => $coords[1] ?? null,
                'lon' => $coords[0] ?? null,
                'raw' => $feature,
            ];
        }

        return $results;
    }

    /**
     * Reverse geocode: get address from coordinates (lat, lon)
     * Returns location details from BAN (French API only)
     */
    public function reverseGeocode(float $lat, float $lon): ?array
    {
        $url = 'https://api-adresse.data.gouv.fr/reverse/';
        $params = [
            'lon' => $lon,
            'lat' => $lat,
        ];

        try {
            $response = $this->http->request('GET', $url, ['query' => $params]);
            $data = $response->toArray();
            $feature = $data['features'][0] ?? null;

            if (!$feature) {
                return null;
            }

            $props = $feature['properties'] ?? [];
            $geom = $feature['geometry'] ?? null;
            $coords = $geom['coordinates'] ?? null;

            return [
                'address' => $props['label'] ?? '',
                'postcode' => $props['postcode'] ?? null,
                'postalCode' => $props['postcode'] ?? null,
                'city' => $props['city'] ?? null,
                'street' => $props['street'] ?? null,
                'lat' => $coords[1] ?? $lat,
                'lon' => $coords[0] ?? $lon,
                'raw' => $feature,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Adresse API reverse geocode failed', ['lat' => $lat, 'lon' => $lon, 'exception' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Search street names in a given city/postcode (BAN)
     */
    public function searchStreets(string $street, string $postalCode, string $city, int $limit = 10): array
    {
        $url = 'https://api-adresse.data.gouv.fr/search/';
        $queries = [
            [
                'q' => $street,
                'limit' => $limit,
                'type' => 'street',
                'postcode' => $postalCode,
                'city' => $city,
            ],
            [
                'q' => $street,
                'limit' => $limit,
                'type' => 'street',
                'postcode' => $postalCode,
            ],
            [
                'q' => $street,
                'limit' => $limit,
                'type' => 'street',
                'city' => $city,
            ],
            [
                'q' => $street,
                'limit' => $limit,
                'type' => 'street',
            ],
        ];

        foreach ($queries as $params) {
            try {
                $response = $this->http->request('GET', $url, ['query' => $params]);
                $data = $response->toArray();
            } catch (\Throwable $e) {
                $this->logger->error('Adresse API street search failed', [
                    'exception' => $e->getMessage(),
                    'street' => $street,
                    'postalCode' => $postalCode,
                    'city' => $city,
                    'params' => $params,
                ]);
                continue;
            }

            $results = [];
            foreach ($data['features'] ?? [] as $feature) {
                $props = $feature['properties'] ?? [];
                $results[] = [
                    'label' => $props['label'] ?? ($props['name'] ?? ''),
                    'name' => $props['name'] ?? null,
                    'postcode' => $props['postcode'] ?? null,
                    'city' => $props['city'] ?? null,
                    'raw' => $feature,
                ];
            }

            if (!empty($results)) {
                return $results;
            }
        }

        return [];
    }
}

