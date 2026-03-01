<?php

namespace App\Shipping\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MapboxGeocodingService
{
    public function __construct(
        private HttpClientInterface $http,
        private string $accessToken,
        private LoggerInterface $logger,
    ) {}

    /**
     * Autocomplete using Mapbox Places API
     * Returns an array of normalized suggestions
     */
    public function autocomplete(string $query, ?string $country = null, int $limit = 5): array
    {
        $url = sprintf('https://api.mapbox.com/geocoding/v5/mapbox.places/%s.json', rawurlencode($query));

        $params = [
            'access_token' => $this->accessToken,
            'limit' => $limit,
        ];

        if ($country) {
            // Mapbox expects ISO 3166 alpha-2, comma separated if multiple
            $params['country'] = strtoupper($country);
        }

        try {
            $response = $this->http->request('GET', $url, ['query' => $params]);
            $data = $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('Mapbox request failed', ['exception' => $e->getMessage(), 'query' => $query]);
            return [];
        }

        $results = [];
        foreach ($data['features'] ?? [] as $feature) {
            $center = $feature['center'] ?? null;
            $lat = $center[1] ?? null;
            $lon = $center[0] ?? null;

            $components = $this->extractComponents($feature);

            $results[] = [
                'id' => $feature['id'] ?? null,
                'label' => $feature['place_name'] ?? ($feature['text'] ?? ''),
                'place_type' => $feature['place_type'] ?? [],
                'street' => $components['street'] ?? null,
                'postalCode' => $components['postalCode'] ?? null,
                'postcode' => $components['postalCode'] ?? null,
                'city' => $components['city'] ?? null,
                'country' => $components['country'] ?? null,
                'countryCode' => $components['countryCode'] ?? null,
                'region' => $components['region'] ?? null,
                'lat' => $lat,
                'lon' => $lon,
                'raw' => $feature,
            ];
        }

        return $results;
    }

    /**
     * Reverse geocode: get address from coordinates (lat, lon)
     * Returns location details
     */
    public function reverseGeocode(float $lat, float $lon): ?array
    {
        $url = sprintf('https://api.mapbox.com/geocoding/v5/mapbox.places/%s,%s.json', $lon, $lat);
        $params = [
            'access_token' => $this->accessToken,
            'types' => 'address,place,region,country',
            'limit' => 1,
        ];

        try {
            $response = $this->http->request('GET', $url, ['query' => $params]);
            $data = $response->toArray();
            $feature = $data['features'][0] ?? null;

            if (!$feature) {
                return null;
            }

            $center = $feature['center'] ?? null;
            $props = $feature['properties'] ?? [];
            $components = $this->extractComponents($feature);

            return [
                'address' => $feature['place_name'] ?? '',
                'short_code' => $props['short_code'] ?? null,
                'wikidata' => $props['wikidata'] ?? null,
                'street' => $components['street'] ?? null,
                'postalCode' => $components['postalCode'] ?? null,
                'city' => $components['city'] ?? null,
                'country' => $components['country'] ?? null,
                'countryCode' => $components['countryCode'] ?? null,
                'region' => $components['region'] ?? null,
                'lat' => $center[1] ?? $lat,
                'lon' => $center[0] ?? $lon,
                'raw' => $feature,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Mapbox reverse geocode failed', ['lat' => $lat, 'lon' => $lon, 'exception' => $e->getMessage()]);
            return null;
        }
    }

    private function extractComponents(array $feature): array
    {
        $context = $feature['context'] ?? [];
        $map = [];

        foreach ($context as $item) {
            $id = $item['id'] ?? '';
            $prefix = strtok($id, '.');
            if ($prefix && !isset($map[$prefix])) {
                $map[$prefix] = $item;
            }
        }

        $placeType = $feature['place_type'] ?? [];

        $street = null;
        if (in_array('address', $placeType, true)) {
            $number = $feature['address'] ?? '';
            $text = $feature['text'] ?? '';
            $street = trim(trim($number) . ' ' . $text);
        }

        $postalCode = $map['postcode']['text'] ?? ($feature['properties']['postcode'] ?? null);
        $city = $map['place']['text'] ?? ($map['locality']['text'] ?? ($map['district']['text'] ?? null));
        $region = $map['region']['text'] ?? null;
        $country = $map['country']['text'] ?? null;
        $countryCode = $map['country']['short_code'] ?? null;
        if ($countryCode) {
            $countryCode = strtoupper($countryCode);
        }

        return [
            'street' => $street,
            'postalCode' => $postalCode,
            'city' => $city,
            'region' => $region,
            'country' => $country,
            'countryCode' => $countryCode,
        ];
    }
}

