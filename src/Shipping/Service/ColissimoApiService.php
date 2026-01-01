<?php
// src/Service/ColissimoApiService.php

namespace App\Shipping\Service;

use App\Order\Entity\Order;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ColissimoApiService
{
    // ✅ Ton HS code constant (plantes)
    private const HS_CODE_PLANTS = '121190';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $apiUrl,
        private string $apiKey,
        private array $senderInfo
    ) {}

    public function generateLabel(Order $order): array
    {
        $this->logger->info('Generating Colissimo label', [
            'order' => $order->getOrderNumber(),
        ]);

        if ($this->isOM($order)) {
            return $this->generateOMLabel($order);
        }

        return $this->generateDomesticLabel($order);
    }

    // ------------------------------------------------------------------
    // DOM (Métropole / Monaco / Andorre)
    // ------------------------------------------------------------------

    private function generateDomesticLabel(Order $order): array
    {
        $payload = $this->buildDomesticPayload($order);

        $this->logger->info('Colissimo DOM REST payload', [
            'payload' => json_encode($payload, JSON_PRETTY_PRINT),
        ]);

        try {
            $response = $this->httpClient->request('POST', rtrim($this->apiUrl, '/') . '/generateLabel', [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'apikey' => $this->apiKey,
                ],
            ]);

            $content = $response->getContent(false);

            // multipart (pdf + jsonInfos)
            if ($this->isMultipart($content)) {
                $json = $this->extractJsonFromMultipart($content);
                $pdf  = $this->extractPdfFromMultipart($content);

                // messages d'erreur dans jsonInfos
                if ($json && isset($json['messages'][0]['type']) && $json['messages'][0]['type'] === 'ERROR') {
                    $msg = $json['messages'][0]['messageContent'] ?? 'Erreur Colissimo DOM';
                    throw new \RuntimeException($msg);
                }

                if ($pdf !== null) {
                    return [
                        'success' => true,
                        'trackingNumber' => $json['labelV2Response']['parcelNumber']
                            ?? $json['labelResponse']['parcelNumber']
                            ?? $order->getOrderNumber(),
                        'labelPdf' => base64_encode($pdf),
                        'provider' => 'Colissimo',
                        'rawData' => $json ?: ['pdfExtracted' => true],
                    ];
                }

                throw new \RuntimeException('Réponse multipart DOM sans PDF exploitable.');
            }

            // JSON classique
            $data = $response->toArray(false);

            return [
                'success' => true,
                'trackingNumber' => $data['labelV2Response']['parcelNumber'] ?? $data['labelResponse']['parcelNumber'] ?? null,
                'labelPdf' => $data['labelV2Response']['label'] ?? $data['labelResponse']['label'] ?? null,
                'provider' => 'Colissimo',
                'rawData' => $data,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Colissimo DOM label generation failed', [
                'error' => $e->getMessage(),
                'order' => $order->getOrderNumber(),
            ]);

            throw new \RuntimeException('Erreur génération étiquette DOM: ' . $e->getMessage());
        }
    }

    private function buildDomesticPayload(Order $order): array
    {
        $address = $order->getShippingAddress();
        $customerData = $this->getCustomerData($order, $address);

        // ✅ DOM : poids en grammes (int)
        $totalWeightGrams = 0;
        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            $qty = (int) $item->getQuantity();
            $unitWeightGrams = $this->safeProductWeightGrams($product);
            $totalWeightGrams += ($unitWeightGrams * $qty);
        }

        $addresseeAddress = [
            'lastName' => $customerData['lastName'],
            'firstName' => $customerData['firstName'],
            'line2' => $address->getStreetAddress(),
            'zipCode' => $this->normalizePostalCode($address->getPostalCode()),
            'city' => $address->getCity(),
            'countryCode' => 'FR',
            'email' => $customerData['email'],
            'mobileNumber' => $customerData['phone'],
        ];

        if ($customerData['companyName'] !== null) {
            $addresseeAddress['companyName'] = $customerData['companyName'];
        }

        return [
            'outputFormat' => [
                'x' => 0,
                'y' => 0,
                'outputPrintingType' => 'PDF_A4_300dpi',
            ],
            'letter' => [
                'service' => [
                    'productCode' => 'DOM',
                    'depositDate' => (new \DateTime())->format('Y-m-d'),
                    'orderNumber' => $order->getOrderNumber(),
                    'transportationAmount' => (float) ($order->getShippingCost() ?? 0),
                    'totalAmount' => (float) $order->getTotalAmount(),
                ],
                'parcel' => [
                    'weight' => max(1, (int) $totalWeightGrams),
                ],
                'sender' => [
                    'address' => [
                        'companyName' => $this->senderInfo['company'],
                        'lastName' => $this->senderInfo['lastname'],
                        'firstName' => $this->senderInfo['firstname'],
                        'line2' => $this->senderInfo['address'],
                        'zipCode' => $this->senderInfo['zipcode'],
                        'city' => $this->senderInfo['city'],
                        'countryCode' => $this->senderInfo['country'],
                        'email' => $this->senderInfo['email'],
                        'mobileNumber' => $this->senderInfo['phone'],
                    ],
                ],
                'addressee' => [
                    'address' => $addresseeAddress,
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // OM (CN23)
    // ------------------------------------------------------------------

    private function generateOMLabel(Order $order): array
    {
        $payload = $this->buildOMPayload($order);

        $this->logger->info('Colissimo OM REST payload', [
            'payload' => json_encode($payload, JSON_PRETTY_PRINT),
        ]);

        try {
            $response = $this->httpClient->request('POST', rtrim($this->apiUrl, '/') . '/generateLabel', [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'apikey' => $this->apiKey,
                ],
            ]);

            $content = $response->getContent(false);

            // multipart (jsonInfos + pdf)
            if ($this->isMultipart($content)) {
                $json = $this->extractJsonFromMultipart($content);
                $pdf  = $this->extractPdfFromMultipart($content);

                // Erreur Colissimo dans jsonInfos
                if ($json && isset($json['messages'][0]['type']) && $json['messages'][0]['type'] === 'ERROR') {
                    $msg = $json['messages'][0]['messageContent'] ?? 'Erreur Colissimo OM';
                    throw new \RuntimeException($msg);
                }

                if ($pdf !== null) {
                    return [
                        'success' => true,
                        'trackingNumber' => $json['labelResponse']['parcelNumber']
                            ?? $json['labelV2Response']['parcelNumber']
                            ?? $order->getOrderNumber(),
                        'labelPdf' => base64_encode($pdf),
                        'provider' => 'Colissimo OM',
                        'rawData' => $json ?: ['pdfExtracted' => true],
                    ];
                }

                throw new \RuntimeException('Réponse multipart OM sans PDF exploitable.');
            }

            // JSON classique
            $data = $response->toArray(false);

            // Certains environnements renvoient directement un base64 dans labelResponse.label
            return [
                'success' => true,
                'trackingNumber' => $data['labelResponse']['parcelNumber'] ?? $data['labelV2Response']['parcelNumber'] ?? $order->getOrderNumber(),
                'labelPdf' => $data['labelResponse']['label'] ?? $data['labelV2Response']['label'] ?? null,
                'provider' => 'Colissimo OM',
                'rawData' => $data,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Colissimo OM label generation failed', [
                'error' => $e->getMessage(),
                'order' => $order->getOrderNumber(),
            ]);

            throw new \RuntimeException('Erreur génération étiquette OM: ' . $e->getMessage());
        }
    }

    /**
     * ✅ OM : cohérence arrondis Colissimo
     * - article.weight : kg (float, 3 décimales)
     * - parcel.weight  : kg (float, 2 décimales)
     * - parcel.weight = somme(articles déjà arrondis) + 0.10 kg (marge de sécurité), min 0.10kg
     */
    private function buildOMPayload(Order $order): array
    {
        $address = $order->getShippingAddress();
        $customerData = $this->getCustomerData($order, $address);

        $articles = [];
        $sumArticlesKg = 0.0;

        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            $qty = (int) $item->getQuantity();

            $unitWeightGrams = $this->safeProductWeightGrams($product);

            // ✅ poids CN23 unitaire (kg) arrondi à 3 décimales (stable)
            $unitWeightKg = $this->gramsToKgCN23($unitWeightGrams);

            $articles[] = [
                'description' => $this->getProductDescription($product),
                'quantity' => $qty,
                'weight' => $unitWeightKg,                // ✅ kg
                'value' => (float) $item->getUnitPrice(),
                'hsCode' => self::HS_CODE_PLANTS,
                'originCountry' => 'FR',
                'currency' => 'EUR',
            ];

            $sumArticlesKg += ($unitWeightKg * $qty);
        }

        // ✅ Poids colis = somme des articles (déjà arrondis) + marge anti-arrondi
        $parcelWeightKg = $this->finalParcelWeightKg($sumArticlesKg);

        $addresseeAddress = [
            'lastName' => $customerData['lastName'],
            'firstName' => $customerData['firstName'],
            'line2' => $address->getStreetAddress(),
            'zipCode' => $this->normalizePostalCode($address->getPostalCode()),
            'city' => $address->getCity(),
            'countryCode' => $this->resolveAddresseeCountryCode($order),
            'email' => $customerData['email'],
            'mobileNumber' => $customerData['phone'],
        ];

        if ($customerData['companyName'] !== null) {
            $addresseeAddress['companyName'] = $customerData['companyName'];
        }

        $this->logger->info('OM weight calculation', [
            'sumArticlesKg' => $sumArticlesKg,
            'parcelWeightKg' => $parcelWeightKg,
            'margin' => $parcelWeightKg - $sumArticlesKg,
        ]);

        return [
            'outputFormat' => [
                'x' => 0,
                'y' => 0,
                'outputPrintingType' => 'PDF_A4_300dpi',
            ],
            'letter' => [
                'service' => [
                    'productCode' => 'COM',
                    'depositDate' => (new \DateTime())->format('Y-m-d'),
                    'orderNumber' => $order->getOrderNumber(),
                    'transportationAmount' => (float) ($order->getShippingCost() ?? 0),
                    'totalAmount' => (float) $order->getTotalAmount(),
                ],
                'parcel' => [
                    'weight' => $parcelWeightKg, // ✅ kg (float 2 déc)
                ],
                'customsDeclarations' => [
                    'includeCustomsDeclarations' => 1,
                    'contents' => [
                        'category' => ['value' => 3],
                        'article' => $articles,
                    ],
                ],
                'sender' => [
                    'address' => [
                        'companyName' => $this->senderInfo['company'],
                        'lastName' => $this->senderInfo['lastname'],
                        'firstName' => $this->senderInfo['firstname'],
                        'line2' => $this->senderInfo['address'],
                        'zipCode' => $this->senderInfo['zipcode'],
                        'city' => $this->senderInfo['city'],
                        'countryCode' => $this->senderInfo['country'],
                        'email' => $this->senderInfo['email'],
                        'mobileNumber' => $this->senderInfo['phone'],
                    ],
                ],
                'addressee' => [
                    'address' => $addresseeAddress,
                ],
            ],
        ];
    }

    private function finalParcelWeightKg(float $sumArticlesKg): float
    {
        // ✅ Marge de sécurité augmentée à 0.10 kg (100g) pour éviter les problèmes d'arrondi
        // Colissimo peut arrondir différemment de notre côté, donc on garde une marge confortable
        $kg = $sumArticlesKg + 0.10;

        // arrondi 2 décimales pour le colis
        $kg = round($kg, 2);

        // minimum "safe" (évite poids trop faible refusé)
        if ($kg < 0.10) {
            $kg = 0.10;
        }

        // max 30 kg Colissimo
        if ($kg > 30.00) {
            $kg = 30.00;
        }

        return $kg;
    }

    // ------------------------------------------------------------------
    // Customer data
    // ------------------------------------------------------------------

    private function getCustomerData(Order $order, $address): array
    {
        if ($order->getOwner()) {
            $user = $order->getOwner();
            return [
                'email' => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'phone' => $user->getPhone() ?? '0600000000',
                'companyName' => null,
            ];
        }

        if ($order->getGuestEmail()) {
            return [
                'email' => $order->getGuestEmail(),
                'firstName' => $order->getGuestFirstName(),
                'lastName' => $order->getGuestLastName(),
                'phone' => $order->getGuestPhone() ?? '0600000000',
                'companyName' => null,
            ];
        }

        return [
            'email' => 'noreply@khamareo.com',
            'firstName' => $address->getFirstName() ?? 'Client',
            'lastName' => $address->getLastName() ?? 'Khamareo',
            'phone' => $address->getPhone() ?? '0600000000',
            'companyName' => $address->getCompanyName(),
        ];
    }

    // ------------------------------------------------------------------
    // Postal / CountryCode resolver (FR + OM + AD)
    // ------------------------------------------------------------------

    private function normalizePostalCode(?string $postalCode): string
    {
        $postalCode = (string) $postalCode;
        $postalCode = strtoupper(trim($postalCode));
        return str_replace([' ', "\t", "\n", "\r"], '', $postalCode);
    }

    private function resolveCountryCodeFromPostalCode(string $postalCode): ?string
    {
        $postalCode = $this->normalizePostalCode($postalCode);

        // Andorre: AD100 etc.
        if (str_starts_with($postalCode, 'AD')) {
            return 'AD';
        }

        if (!preg_match('/^\d{5}$/', $postalCode)) {
            return null;
        }

        $cp = (int) $postalCode;

        // MF: 97024, 97051-97080, 97149-97150
        if ($cp === 97024 || ($cp >= 97051 && $cp <= 97080) || ($cp >= 97149 && $cp <= 97150)) {
            return 'MF';
        }

        // BL: 97012-97022, 97049, 97095-97099, 97133
        if (($cp >= 97012 && $cp <= 97022) || $cp === 97049 || ($cp >= 97095 && $cp <= 97099) || $cp === 97133) {
            return 'BL';
        }

        // GP: 97100–97132, 97134–97148, 97151–97199
        if (($cp >= 97100 && $cp <= 97132) || ($cp >= 97134 && $cp <= 97148) || ($cp >= 97151 && $cp <= 97199)) {
            return 'GP';
        }

        // MQ: 97200–97299
        if ($cp >= 97200 && $cp <= 97299) {
            return 'MQ';
        }

        // GF: 97300–97399
        if ($cp >= 97300 && $cp <= 97399) {
            return 'GF';
        }

        // RE: 97400–97490, 97820–97899
        if (($cp >= 97400 && $cp <= 97490) || ($cp >= 97820 && $cp <= 97899)) {
            return 'RE';
        }

        // YT: 97600–97690
        if ($cp >= 97600 && $cp <= 97690) {
            return 'YT';
        }

        // PM: 97500
        if ($cp === 97500) {
            return 'PM';
        }

        // FR: 00001..95999 + Monaco 98000..98091
        if (($cp >= 1 && $cp <= 95999) || ($cp >= 98000 && $cp <= 98091)) {
            return 'FR';
        }

        return null;
    }

    private function resolveAddresseeCountryCode(Order $order): string
    {
        $address = $order->getShippingAddress();
        $postalCode = $address ? $address->getPostalCode() : null;

        $fromPostal = $this->resolveCountryCodeFromPostalCode((string) $postalCode);
        if ($fromPostal) {
            return $fromPostal;
        }

        $country = strtoupper(trim((string) ($address?->getCountry() ?? '')));
        if (preg_match('/^[A-Z]{2}$/', $country)) {
            return $country;
        }

        return 'FR';
    }

    private function isOM(Order $order): bool
    {
        return $this->resolveAddresseeCountryCode($order) !== 'FR';
    }

    // ------------------------------------------------------------------
    // Weights
    // ------------------------------------------------------------------

    /**
     * Retourne un poids unitaire en GRAMMES (int), robuste.
     * - null => 500g
     * - si <= 30 => considéré comme KG (fixtures Faker 0.1..5.0) => converti en g
     * - sinon => considéré comme grammes déjà
     */
    private function safeProductWeightGrams($product): int
    {
        $w = $product->getWeight();

        if ($w === null || !is_numeric($w)) {
            return 500;
        }

        $w = (float) $w;

        if ($w > 0 && $w <= 30) {
            return max(1, (int) round($w * 1000));
        }

        return max(1, (int) round($w));
    }

    /**
     * CN23: poids net unitaire en kg (float). On garde 3 décimales.
     */
    private function gramsToKgCN23(int $grams): float
    {
        $kg = round($grams / 1000, 3);
        return max(0.001, $kg);
    }

    // ------------------------------------------------------------------
    // Multipart helpers
    // ------------------------------------------------------------------

    private function isMultipart(string $content): bool
    {
        return str_contains($content, '--uuid:');
    }

    private function extractPdfFromMultipart(string $content): ?string
    {
        $pdfStart = strpos($content, '%PDF');
        if ($pdfStart === false) {
            return null;
        }

        $pdfEnd = strrpos($content, '%%EOF');
        if ($pdfEnd === false || $pdfEnd <= $pdfStart) {
            return null;
        }

        return substr($content, $pdfStart, $pdfEnd - $pdfStart + 5);
    }

    private function extractJsonFromMultipart(string $content): array
    {
        // On prend le premier JSON du multipart (jsonInfos)
        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if ($start === false || $end === false || $end <= $start) {
            return [];
        }

        $jsonStr = substr($content, $start, $end - $start + 1);
        $decoded = json_decode($jsonStr, true);

        return is_array($decoded) ? $decoded : [];
    }

    // ------------------------------------------------------------------
    // Product description
    // ------------------------------------------------------------------

    private function getProductDescription($product): string
    {
        $name = strtolower((string) $product->getName());

        if (str_contains($name, 'huile')) return 'Huile végétale naturelle';
        if (str_contains($name, 'beurre')) return 'Beurre végétal';
        if (str_contains($name, 'poudre')) return 'Poudre végétale';
        if (str_contains($name, 'savon')) return 'Savon naturel';

        return 'Produit naturel';
    }
}