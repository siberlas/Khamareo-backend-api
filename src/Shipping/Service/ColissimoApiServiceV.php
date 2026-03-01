<?php

namespace App\Shipping\Service;

use App\Order\Entity\Order;
use App\Shipping\Enum\DestinationZone;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Service d'intégration avec l'API Colissimo
 * 
 * Gère la génération d'étiquettes pour :
 * - France métropolitaine (DOM)
 * - Outre-mer (COM) avec CN23
 * - Union Européenne (COLI) sans CN23
 * - International (COLI) avec CN23
 */
class ColissimoApiServiceV
{
    private const HS_CODE_PLANTS = '121190';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private DestinationClassifier $destinationClassifier,
        private string $apiUrl,
        private string $apiKey,
        private array $senderInfo,
        private array $productCodes = []
    ) {}

    /**
     * Génère une étiquette Colissimo pour une commande
     * 
     * Détecte automatiquement la zone de destination et adapte :
     * - Le code produit (DOM/COM/COLI)
     * - L'inclusion ou non du CN23
     */
    public function generateLabel(Order $order): array
    {
        $this->logger->info('Generating Colissimo label', [
            'order' => $order->getOrderNumber(),
        ]);

        // Classifier la destination
        $address = $order->getShippingAddress();
        $zone = $this->destinationClassifier->classify(
            $address->getPostalCode(),
            $address->getCountry()
        );

        $this->logger->info('Destination classified', [
            'order' => $order->getOrderNumber(),
            'zone' => $zone->value,
            'product_code' => $zone->getProductCode(),
            'requires_cn23' => $zone->requiresCN23(),
        ]);

        // Construire le payload selon la zone
        $payload = $this->buildPayload($order, $zone);

        $this->logger->info('Colissimo API payload', [
            'order' => $order->getOrderNumber(),
            'zone' => $zone->value,
            'payload' => json_encode($payload, JSON_PRETTY_PRINT),
        ]);

        try {
            // Appeler l'API Colissimo
            $response = $this->httpClient->request('POST', rtrim($this->apiUrl, '/') . '/generateLabel', [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => $zone->requiresCN23() ? 'multipart/mixed' : 'application/json',
                    'apikey' => $this->apiKey,
                ],
            ]);

            $content = $response->getContent(false);
            $headers = $response->getHeaders(false);
            $contentType = $headers['content-type'][0] ?? null;

            // Parser la réponse
            if ($zone->requiresCN23() && $this->isMultipart($content, $contentType)) {
                return $this->parseMultipartResponse($content, $contentType, $order, $zone);
            } else {
                return $this->parseJsonResponse($content, $response, $order, $zone);
            }

        } catch (\Exception $e) {
            $this->logger->error('Colissimo label generation failed', [
                'order' => $order->getOrderNumber(),
                'zone' => $zone->value,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Erreur génération étiquette Colissimo: ' . $e->getMessage());
        }
    }

    /**
     * Construit le payload pour l'API Colissimo
     */
    private function buildPayload(Order $order, DestinationZone $zone): array
    {
        $address = $order->getShippingAddress();
        $customerData = $this->getCustomerData($order, $address);

        // Calculer le poids total
        $totalWeightGrams = $this->calculateTotalWeight($order);

        // Adresse destinataire
        $addresseeAddress = [
            'lastName' => $customerData['lastName'],
            'firstName' => $customerData['firstName'],
            'line2' => $address->getStreetAddress(),
            'zipCode' => $this->normalizePostalCode($address->getPostalCode()),
            'city' => $address->getCity(),
            'countryCode' => $this->resolveCountryCode($address, $zone),
            'email' => $customerData['email'],
            'mobileNumber' => $customerData['phone'],
        ];

        if ($customerData['companyName']) {
            $addresseeAddress['companyName'] = $customerData['companyName'];
        }

        // Payload de base
        $payload = [
            'outputFormat' => [
                'x' => 0,
                'y' => 0,
                'outputPrintingType' => 'PDF_A4_300dpi',
            ],
            'letter' => [
                'service' => [
                    'productCode' => $zone->getProductCode(),
                    'depositDate' => (new \DateTime())->format('Y-m-d'),
                    'orderNumber' => $order->getOrderNumber(),
                    'transportationAmount' => (float) ($order->getShippingCost() ?? 0),
                    'totalAmount' => (float) $order->getTotalAmount(),
                ],
                'parcel' => [
                    'weight' => max(0.01, round($totalWeightGrams / 1000, 3)), // KG
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

        // Ajouter la déclaration en douane si nécessaire
        if ($zone->requiresCN23()) {
            $payload['letter']['customsDeclarations'] = $this->buildCustomsDeclaration($order);
        }

        return $payload;
    }

    /**
     * Construit la déclaration en douane (CN23)
     */
    private function buildCustomsDeclaration(Order $order): array
    {
        $articles = [];

        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            $qty = (int) $item->getQuantity();
            $unitWeightGrams = $this->safeProductWeightGrams($product);
            $unitWeightKg = max(0.001, round($unitWeightGrams / 1000, 3));

            $articles[] = [
                'description' => $this->getProductDescription($product),
                'quantity' => $qty,
                'weight' => $unitWeightKg,
                'value' => (float) $item->getUnitPrice(),
                'hsCode' => self::HS_CODE_PLANTS,
                'originCountry' => 'FR',
                'currency' => 'EUR',
            ];
        }

        return [
            'includeCustomsDeclarations' => 1,
            'contents' => [
                'category' => ['value' => 3], // Marchandise commerciale
                'article' => $articles,
            ],
        ];
    }

    /**
     * Parse une réponse multipart (avec CN23)
     */
    private function parseMultipartResponse(
        string $content,
        ?string $contentType,
        Order $order,
        DestinationZone $zone
    ): array {
        $parts = $this->parseMultipartMixed($content, $contentType);

        $json = $parts['jsonInfos'] ?? [];
        $labelBytes = $parts['label'] ?? null;
        $cn23Bytes = $parts['cn23'] ?? null;

        // Vérifier les erreurs Colissimo
        if (is_array($json) && isset($json['messages'][0]['type']) && $json['messages'][0]['type'] === 'ERROR') {
            $msg = $json['messages'][0]['messageContent'] ?? 'Erreur Colissimo';
            throw new \RuntimeException($msg);
        }

        // Extraire le tracking number
        $trackingNumber = $json['parcelNumber']
            ?? $json['labelResponse']['parcelNumber']
            ?? $json['labelV2Response']['parcelNumber']
            ?? $order->getOrderNumber();

        if ($labelBytes === null) {
            throw new \RuntimeException('Réponse multipart sans label.');
        }

        return [
            'success' => true,
            'trackingNumber' => $trackingNumber,
            'labelPdf' => base64_encode($labelBytes),
            'cn23Pdf' => $cn23Bytes ? base64_encode($cn23Bytes) : null,
            'provider' => 'Colissimo ' . $zone->getLabel(),
            'zone' => $zone->value,
            'rawData' => $json ?: ['multipartParsed' => true],
        ];
    }

    /**
     * Parse une réponse JSON (sans CN23)
     */
    private function parseJsonResponse(
        string $content,
        ResponseInterface $response,
        Order $order,
        DestinationZone $zone
    ): array {
        // Vérifier si c'est du multipart déguisé
        if ($this->isMultipart($content)) {
            $json = $this->extractJsonFromMultipart($content);
            $pdf = $this->extractPdfFromMultipart($content);

            if ($json && isset($json['messages'][0]['type']) && $json['messages'][0]['type'] === 'ERROR') {
                $msg = $json['messages'][0]['messageContent'] ?? 'Erreur Colissimo';
                throw new \RuntimeException($msg);
            }

            if ($pdf !== null) {
                return [
                    'success' => true,
                    'trackingNumber' => $json['labelV2Response']['parcelNumber']
                        ?? $json['labelResponse']['parcelNumber']
                        ?? $order->getOrderNumber(),
                    'labelPdf' => base64_encode($pdf),
                    'cn23Pdf' => null,
                    'provider' => 'Colissimo ' . $zone->getLabel(),
                    'zone' => $zone->value,
                    'rawData' => $json ?: ['pdfExtracted' => true],
                ];
            }
        }

        // JSON pur
        $data = $response->toArray(false);

        return [
            'success' => true,
            'trackingNumber' => $data['labelV2Response']['parcelNumber']
                ?? $data['labelResponse']['parcelNumber']
                ?? $order->getOrderNumber(),
            'labelPdf' => $data['labelV2Response']['label']
                ?? $data['labelResponse']['label']
                ?? null,
            'cn23Pdf' => null,
            'provider' => 'Colissimo ' . $zone->getLabel(),
            'zone' => $zone->value,
            'rawData' => $data,
        ];
    }

    /**
     * Calcule le poids total de la commande en grammes
     */
    private function calculateTotalWeight(Order $order): int
    {
        $totalGrams = 0;

        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            $qty = (int) $item->getQuantity();
            $unitWeightGrams = $this->safeProductWeightGrams($product);
            $totalGrams += ($unitWeightGrams * $qty);
        }

        return $totalGrams;
    }

    /**
     * Récupère les données client de manière sécurisée
     */
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

    /**
     * Résout le code pays pour Colissimo
     */
    private function resolveCountryCode($address, DestinationZone $zone): string
    {
        // Pour les zones françaises, toujours retourner le code spécifique
        if ($zone === DestinationZone::FRANCE_METRO) {
            // Vérifier si c'est Monaco ou Andorre
            $postalCode = $this->normalizePostalCode($address->getPostalCode());
            if ($postalCode && str_starts_with($postalCode, 'AD')) {
                return 'AD';
            }
            if ($postalCode && preg_match('/^98\d{3}$/', $postalCode)) {
                return 'MC';
            }
            return 'FR';
        }

        if ($zone === DestinationZone::OUTRE_MER) {
            // Déterminer le code ISO du territoire
            $postalCode = $this->normalizePostalCode($address->getPostalCode());
            if ($postalCode && preg_match('/^\d{5}$/', $postalCode)) {
                $cp = (int) $postalCode;

                if ($cp >= 97100 && $cp <= 97199) return 'GP'; // Guadeloupe
                if ($cp >= 97200 && $cp <= 97299) return 'MQ'; // Martinique
                if ($cp >= 97300 && $cp <= 97399) return 'GF'; // Guyane
                if (($cp >= 97400 && $cp <= 97490) || ($cp >= 97820 && $cp <= 97899)) return 'RE'; // Réunion
                if ($cp >= 97600 && $cp <= 97690) return 'YT'; // Mayotte
                if ($cp === 97500) return 'PM'; // Saint-Pierre-et-Miquelon
                if (($cp >= 97150 && $cp <= 97150) || $cp === 97133) return 'MF'; // Saint-Martin
                if (($cp >= 97012 && $cp <= 97022) || $cp === 97049) return 'BL'; // Saint-Barthélemy
            }
        }

        // Pour les autres zones, utiliser le code pays de l'adresse
        $country = strtoupper(trim((string) ($address->getCountry() ?? '')));

        if (preg_match('/^[A-Z]{2}$/', $country)) {
            return $country;
        }

        // Fallback
        return 'FR';
    }

    /**
     * Normalise un code postal
     */
    private function normalizePostalCode(?string $postalCode): string
    {
        $postalCode = (string) $postalCode;
        $postalCode = strtoupper(trim($postalCode));
        return str_replace([' ', "\t", "\n", "\r", '-'], '', $postalCode);
    }

    /**
     * Récupère le poids du produit de manière sécurisée
     */
    private function safeProductWeightGrams($product): int
    {
        $w = $product->getWeight();

        if ($w === null || !is_numeric($w) || $w <= 0) {
            return 500; // Default 500g
        }

        return (int) $w;
    }

    /**
     * Génère une description pour le CN23
     */
    private function getProductDescription($product): string
    {
        $name = strtolower((string) $product->getName());

        if (str_contains($name, 'huile')) return 'Huile végétale naturelle';
        if (str_contains($name, 'beurre')) return 'Beurre végétal';
        if (str_contains($name, 'poudre')) return 'Poudre végétale';
        if (str_contains($name, 'savon')) return 'Savon naturel';

        return 'Produit naturel';
    }

    /**
     * Vérifie si le contenu est multipart
     */
    private function isMultipart(string $content, ?string $contentType = null): bool
    {
        if ($contentType && str_contains(strtolower($contentType), 'multipart')) {
            return true;
        }
        return str_contains($content, '--uuid:');
    }

    /**
     * Parse une réponse multipart/mixed
     */
    private function parseMultipartMixed(string $content, ?string $contentType): array
    {
        $result = [
            'jsonInfos' => null,
            'label' => null,
            'cn23' => null,
            'proforma' => null,
        ];

        $boundary = $this->extractBoundary($contentType);

        if (!$boundary) {
            if (preg_match('/--uuid:[a-f0-9\-]+/i', $content, $matches)) {
                $boundary = $matches[0];
            } else {
                $this->logger->error('Cannot extract multipart boundary');
                return $result;
            }
        }

        $parts = explode($boundary, $content);

        foreach ($parts as $part) {
            $part = trim($part);

            if (empty($part) || $part === '--') {
                continue;
            }

            $headerEndPos = strpos($part, "\r\n\r\n");
            if ($headerEndPos === false) {
                $headerEndPos = strpos($part, "\n\n");
            }

            if ($headerEndPos === false) {
                continue;
            }

            $headers = substr($part, 0, $headerEndPos);
            $body = substr($part, $headerEndPos + 4);

            $partContentType = $this->extractHeaderValue($headers, 'Content-Type');
            $contentDisposition = $this->extractHeaderValue($headers, 'Content-Disposition');
            $contentId = $this->extractHeaderValue($headers, 'Content-ID');
            $partName = $this->extractPartName($contentDisposition, $contentId);

            if ($partContentType && str_contains(strtolower($partContentType), 'application/json')) {
                $decoded = json_decode($body, true);
                $result['jsonInfos'] = is_array($decoded) ? $decoded : [];
            } elseif ($partName) {
                $partNameLower = strtolower($partName);

                if (in_array($partNameLower, ['label', 'pdf', 'labelpdf', 'etiquette'])) {
                    $result['label'] = $body;
                } elseif (in_array($partNameLower, ['cn23', 'customsdeclaration', 'customs'])) {
                    $result['cn23'] = $body;
                } elseif (in_array($partNameLower, ['proforma', 'proformainvoice'])) {
                    $result['proforma'] = $body;
                }
            }
        }

        return $result;
    }

    private function extractBoundary(?string $contentType): ?string
    {
        if (!$contentType) return null;
        if (preg_match('/boundary=([^;\s]+)/i', $contentType, $matches)) {
            return '--' . trim($matches[1], '"');
        }
        return null;
    }

    private function extractHeaderValue(string $headers, string $headerName): ?string
    {
        $pattern = '/' . preg_quote($headerName, '/') . ':\s*(.+)/i';
        if (preg_match($pattern, $headers, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    private function extractPartName(?string $contentDisposition, ?string $contentId = null): ?string
    {
        if ($contentDisposition && preg_match('/name=["\']?([^"\';\s]+)["\']?/i', $contentDisposition, $matches)) {
            return $matches[1];
        }
        if ($contentId && preg_match('/<([^>]+)>/i', $contentId, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function extractPdfFromMultipart(string $content): ?string
    {
        $pdfStart = strpos($content, '%PDF');
        if ($pdfStart === false) return null;

        $pdfEnd = strrpos($content, '%%EOF');
        if ($pdfEnd === false || $pdfEnd <= $pdfStart) return null;

        return substr($content, $pdfStart, $pdfEnd - $pdfStart + 5);
    }

    private function extractJsonFromMultipart(string $content): array
    {
        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if ($start === false || $end === false || $end <= $start) {
            return [];
        }

        $jsonStr = substr($content, $start, $end - $start + 1);
        $decoded = json_decode($jsonStr, true);

        return is_array($decoded) ? $decoded : [];
    }
}