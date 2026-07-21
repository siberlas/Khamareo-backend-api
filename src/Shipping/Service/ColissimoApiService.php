<?php
// src/Service/ColissimoApiService.php

namespace App\Shipping\Service;

use App\Order\Entity\Order;
use App\Shipping\Entity\Parcel;
use App\Shipping\Enum\DestinationZone;
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
        private array $senderInfo,
        private DestinationClassifier $destinationClassifier,
        private array $productCodes = [],
    ) {}

    private function resolveProductCode(DestinationZone $zone): string
    {
        return match ($zone) {
            DestinationZone::FRANCE_METRO      => $this->productCodes['fr']     ?? 'DOM',
            DestinationZone::OUTRE_MER         => $this->productCodes['om']     ?? 'COM',
            DestinationZone::UNION_EUROPEENNE  => $this->productCodes['eu']     ?? 'DOS',
            DestinationZone::EUROPE_HORS_UE    => $this->productCodes['eu_out'] ?? 'COLD',
            DestinationZone::INTERNATIONAL     => $this->productCodes['intl']   ?? 'COLI',
        };
    }

    public function generateLabel(Order $order): array
    {
        $this->logger->info('Generating Colissimo label', [
            'order' => $order->getOrderNumber(),
        ]);

        $zone = $this->resolveZone($order);

        if ($zone === DestinationZone::FRANCE_METRO) {
            return $this->generateDomesticLabel($order);
        }

        return $this->generateOMLabel($order, $zone);
    }

    /**
     * Génère une étiquette pour un colis spécifique (multi-colis).
     * Utilise le poids du colis et ses items pour le CN23.
     * Retourne ['success', 'trackingNumber', 'labelPdf' (base64), 'cn23Pdf' (base64|null), 'rawData']
     */
    public function generateLabelForParcel(Parcel $parcel): array
    {
        $order = $parcel->getOrder();
        $zone  = $this->resolveZone($order);

        $this->logger->info('Generating Colissimo label for parcel', [
            'parcel_id'    => $parcel->getId()->toRfc4122(),
            'parcel_number'=> $parcel->getParcelNumber(),
            'order'        => $order->getOrderNumber(),
            'weight_grams' => $parcel->getWeightGrams(),
            'zone'         => $zone->value,
            'product_code' => $this->resolveProductCode($zone),
        ]);

        if ($zone === DestinationZone::FRANCE_METRO) {
            return $this->generateDomesticParcelLabel($parcel);
        }

        return $this->generateOMParcelLabel($parcel, $zone);
    }

    private function resolveZone(Order $order): DestinationZone
    {
        $address = $order->getShippingAddress();
        return $this->destinationClassifier->classify(
            $address?->getPostalCode(),
            $address?->getCountry()
        );
    }

    private function generateDomesticParcelLabel(Parcel $parcel): array
    {
        $order = $parcel->getOrder();
        $payload = $this->buildDomesticParcelPayload($parcel);

        $this->logger->info('Colissimo DOM parcel payload', [
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

            if ($this->isMultipart($content)) {
                $json = $this->extractJsonFromMultipart($content);
                $pdf  = $this->extractPdfFromMultipart($content);

                if ($json && isset($json['messages'][0]['type']) && $json['messages'][0]['type'] === 'ERROR') {
                    $msg = $json['messages'][0]['messageContent'] ?? 'Erreur Colissimo DOM';
                    throw new \RuntimeException($msg);
                }

                if ($pdf !== null) {
                    $cn23 = $this->extractCn23FromMultipart($content);
                    return [
                        'success' => true,
                        'trackingNumber' => $json['labelV2Response']['parcelNumber']
                            ?? $json['labelResponse']['parcelNumber']
                            ?? $order->getOrderNumber() . '-P' . $parcel->getParcelNumber(),
                        'labelPdf' => base64_encode($pdf),
                        'cn23Pdf' => $cn23 !== null ? base64_encode($cn23) : null,
                        'rawData' => $json ?: ['pdfExtracted' => true],
                    ];
                }

                throw new \RuntimeException('Réponse multipart DOM sans PDF exploitable.');
            }

            $data = $response->toArray(false);

            return [
                'success' => true,
                'trackingNumber' => $data['labelV2Response']['parcelNumber'] ?? $data['labelResponse']['parcelNumber'] ?? null,
                'labelPdf' => $data['labelV2Response']['label'] ?? $data['labelResponse']['label'] ?? null,
                'cn23Pdf' => null,
                'rawData' => $data,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Colissimo DOM parcel label generation failed', [
                'error' => $e->getMessage(),
                'parcel_id' => $parcel->getId()->toRfc4122(),
            ]);

            throw new \RuntimeException('Erreur génération étiquette DOM: ' . $e->getMessage());
        }
    }

    private function buildDomesticParcelPayload(Parcel $parcel): array
    {
        $order = $parcel->getOrder();
        $address = $order->getShippingAddress();
        $customerData = $this->getCustomerData($order, $address);

        $totalWeightGrams = max(1, (int) ($parcel->getWeightGrams() ?? 500));
        $parcelWeightKg   = $this->finalParcelWeightKg($totalWeightGrams / 1000);

        // Référence unique par colis
        $parcelRef = $order->getOrderNumber() . '-P' . $parcel->getParcelNumber();

        $addresseeAddress = [
            'lastName' => $this->normalizeAddressField($customerData['lastName']),
            'firstName' => $this->normalizeAddressField($customerData['firstName']),
            'line2' => $this->normalizeAddressField($address->getStreetAddress()),
            'zipCode' => $this->normalizePostalCode($address->getPostalCode()),
            'city' => $this->normalizeAddressField($address->getCity()),
            // Andorre (CP "ADxxx") n'est pas un format postal FR : Colissimo rejette
            // sinon la combinaison avec un "Le code postal est inconnu/incorrect".
            'countryCode' => $this->resolveCountryCodeFromPostalCode($address->getPostalCode()) ?? 'FR',
            'email' => $customerData['email'],
            'mobileNumber' => $customerData['phone'],
        ];

        if (!empty($address->getAddressComplement())) {
            $addresseeAddress['line3'] = $this->normalizeAddressField($address->getAddressComplement());
        }

        if ($customerData['companyName'] !== null) {
            $addresseeAddress['companyName'] = $this->normalizeAddressField($customerData['companyName']);
        }

        $letter = [
            'service' => [
                'productCode' => 'DOM',
                'depositDate' => (new \DateTime())->format('Y-m-d'),
                'orderNumber' => $parcelRef,
                'transportationAmount' => (float) ($order->getShippingCost() ?? 0),
                'totalAmount' => (float) $order->getTotalAmount(),
            ],
            'parcel' => [
                'weight' => $parcelWeightKg,
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
        ];

        // Andorre (hors FR malgré le produit DOM) : Colissimo rejette avec l'erreur
        // 30500 "Le contenu du colis n'a pas été transmis" si customsDeclarations
        // est absent, alors même que la doc officielle l'indique "non requis" pour
        // ce produit — comportement constaté empiriquement, pas documenté.
        if ($addresseeAddress['countryCode'] !== 'FR') {
            $letter['customsDeclarations'] = $this->buildParcelCustomsContents($parcel);
        }

        return [
            'outputFormat' => [
                'x' => 0,
                'y' => 0,
                'outputPrintingType' => 'PDF_A4_300dpi',
            ],
            'letter' => $letter,
        ];
    }

    /**
     * Bloc customsDeclarations minimal pour un colis dont le produit est DOM
     * mais la destination hors France (cf. Andorre dans buildDomesticParcelPayload).
     */
    private function buildParcelCustomsContents(Parcel $parcel): array
    {
        $articles = [];
        foreach ($parcel->getItems() as $parcelItem) {
            $orderItem = $parcelItem->getOrderItem();
            $product = $orderItem?->getProduct();
            if (!$product) {
                continue;
            }

            $unitWeightGrams = $this->safeProductWeightGrams($product);

            $articles[] = [
                'description' => $this->getProductDescription($product),
                'quantity' => $parcelItem->getQuantity(),
                'weight' => $this->gramsToKgCN23($unitWeightGrams),
                'value' => (float) $orderItem->getUnitPrice(),
                'hsCode' => self::HS_CODE_PLANTS,
                'originCountry' => 'FR',
                'currency' => 'EUR',
            ];
        }

        return [
            'includeCustomsDeclarations' => 0,
            'contents' => [
                'article' => $articles,
                'category' => ['value' => 1],
            ],
        ];
    }

    private function generateOMParcelLabel(Parcel $parcel, DestinationZone $zone = DestinationZone::OUTRE_MER): array
    {
        $order = $parcel->getOrder();
        $payload = $this->buildOMParcelPayload($parcel, $zone);
        $productCode = $payload['letter']['service']['productCode'] ?? '?';

        $this->logger->info('Colissimo parcel payload', [
            'product_code' => $productCode,
            'zone' => $zone->value,
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
            $statusCode = $response->getStatusCode();

            $this->logger->info('🔎 [COLISSIMO RAW RESPONSE] parcel', [
                'parcel_id' => $parcel->getId()->toRfc4122(),
                'product_code' => $productCode,
                'status_code' => $statusCode,
                'is_multipart' => $this->isMultipart($content),
                'content_length' => strlen($content),
                'content_preview' => substr($content, 0, 3000),
            ]);

            if ($this->isMultipart($content)) {
                $json = $this->extractJsonFromMultipart($content);
                $pdf  = $this->extractPdfFromMultipart($content);

                if ($json && isset($json['messages'][0]['type']) && $json['messages'][0]['type'] === 'ERROR') {
                    $msg = $json['messages'][0]['messageContent'] ?? "Erreur Colissimo [$productCode]";
                    throw new \RuntimeException($msg);
                }

                if ($pdf !== null) {
                    $cn23 = $this->extractCn23FromMultipart($content);
                    return [
                        'success' => true,
                        'trackingNumber' => $json['labelResponse']['parcelNumber']
                            ?? $json['labelV2Response']['parcelNumber']
                            ?? $order->getOrderNumber() . '-P' . $parcel->getParcelNumber(),
                        'labelPdf' => base64_encode($pdf),
                        'cn23Pdf' => $cn23 !== null ? base64_encode($cn23) : null,
                        'rawData' => $json ?: ['pdfExtracted' => true],
                    ];
                }

                $this->logger->warning('🔎 [COLISSIMO RAW RESPONSE] multipart sans PDF — json extrait', [
                    'parcel_id' => $parcel->getId()->toRfc4122(),
                    'extracted_json' => $json,
                ]);

                throw new \RuntimeException("Réponse multipart [$productCode] sans PDF exploitable.");
            }

            $data = $response->toArray(false);

            $this->logger->info('🔎 [COLISSIMO RAW RESPONSE] non-multipart — payload décodé', [
                'parcel_id' => $parcel->getId()->toRfc4122(),
                'status_code' => $statusCode,
                'decoded' => $data,
            ]);

            if ($statusCode >= 400) {
                $msg = $data['messages'][0]['messageContent']
                    ?? $data['message']
                    ?? "Erreur Colissimo [$productCode] (HTTP $statusCode)";
                throw new \RuntimeException($msg);
            }

            return [
                'success' => true,
                'trackingNumber' => $data['labelResponse']['parcelNumber'] ?? $data['labelV2Response']['parcelNumber'] ?? null,
                'labelPdf' => $data['labelResponse']['label'] ?? $data['labelV2Response']['label'] ?? null,
                'cn23Pdf' => null,
                'rawData' => $data,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Colissimo parcel label generation failed', [
                'error' => $e->getMessage(),
                'product_code' => $productCode,
                'zone' => $zone->value,
                'parcel_id' => $parcel->getId()->toRfc4122(),
            ]);

            throw new \RuntimeException("Erreur génération étiquette [$productCode]: " . $e->getMessage());
        }
    }

    private function buildOMParcelPayload(Parcel $parcel, DestinationZone $zone = DestinationZone::OUTRE_MER): array
    {
        $order = $parcel->getOrder();
        $address = $order->getShippingAddress();
        $customerData = $this->getCustomerData($order, $address);

        $articles = [];
        $sumArticlesKg = 0.0;

        foreach ($parcel->getItems() as $parcelItem) {
            $orderItem = $parcelItem->getOrderItem();
            $product = $orderItem?->getProduct();
            if (!$product) {
                continue;
            }

            $qty = $parcelItem->getQuantity();
            $unitWeightGrams = $this->safeProductWeightGrams($product);
            $unitWeightKg = $this->gramsToKgCN23($unitWeightGrams);

            $articles[] = [
                'description' => $this->getProductDescription($product),
                'quantity' => $qty,
                'weight' => $unitWeightKg,
                'value' => (float) $orderItem->getUnitPrice(),
                'hsCode' => self::HS_CODE_PLANTS,
                'originCountry' => 'FR',
                'currency' => 'EUR',
            ];

            $sumArticlesKg += ($unitWeightKg * $qty);
        }

        $parcelWeightKg = $this->finalParcelWeightKg($sumArticlesKg);
        $parcelRef = $order->getOrderNumber() . '-P' . $parcel->getParcelNumber();

        $countryCode = $this->resolveAddresseeCountryCode($order);

        $addresseeAddress = [
            'lastName' => $this->normalizeAddressField($customerData['lastName']),
            'firstName' => $this->normalizeAddressField($customerData['firstName']),
            'line2' => $this->normalizeAddressField($address->getStreetAddress()),
            'zipCode' => $this->normalizePostalCode($address->getPostalCode()),
            'city' => $this->normalizeAddressField($address->getCity()),
            'countryCode' => $countryCode,
            'email' => $customerData['email'],
            'mobileNumber' => $customerData['phone'],
        ];

        if (!empty($address->getAddressComplement())) {
            $addresseeAddress['line3'] = $this->normalizeAddressField($address->getAddressComplement());
        }

        // stateOrProvinceCode : champ dédié Colissimo, obligatoire pour les US (distinct de line3)
        if (in_array($countryCode, ['US', 'CA'], true) && method_exists($address, 'getState') && $address->getState()) {
            $addresseeAddress['stateOrProvinceCode'] = strtoupper($address->getState());
        }

        if ($customerData['companyName'] !== null) {
            $addresseeAddress['companyName'] = $this->normalizeAddressField($customerData['companyName']);
        }

        return [
            'outputFormat' => [
                'x' => 0,
                'y' => 0,
                'outputPrintingType' => 'PDF_A4_300dpi',
            ],
            'letter' => [
                'service' => [
                    'productCode' => $this->resolveProductCode($zone),
                    'depositDate' => (new \DateTime())->format('Y-m-d'),
                    'orderNumber' => $parcelRef,
                    'transportationAmount' => (float) ($order->getShippingCost() ?? 0),
                    'totalAmount' => (float) $order->getTotalAmount(),
                ],
                'parcel' => [
                    'weight' => $parcelWeightKg,
                ],
                'customsDeclarations' => [
                    'includeCustomsDeclarations' => 1,
                    'contents' => [
                        'category' => ['value' => 3],
                        'article' => $articles,
                        'eoriNumber' => $this->senderInfo['eori'] ?? '',
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
            'fields' => [
                'field' => [
                    [
                        'key' => 'EORI',
                        'value' => $this->senderInfo['eori'],
                    ],
                ],
            ],
        ];
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

        // DOM : poids total en grammes, converti en kg pour l'API REST
        $totalWeightGrams = 0;
        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            $qty = (int) $item->getQuantity();
            $unitWeightGrams = $this->safeProductWeightGrams($product);
            $totalWeightGrams += ($unitWeightGrams * $qty);
        }
        $parcelWeightKg = $this->finalParcelWeightKg($totalWeightGrams / 1000);

        $addresseeAddress = [
            'lastName' => $this->normalizeAddressField($customerData['lastName']),
            'firstName' => $this->normalizeAddressField($customerData['firstName']),
            'line2' => $this->normalizeAddressField($address->getStreetAddress()),
            'zipCode' => $this->normalizePostalCode($address->getPostalCode()),
            'city' => $this->normalizeAddressField($address->getCity()),
            // Andorre (CP "ADxxx") n'est pas un format postal FR : Colissimo rejette
            // sinon la combinaison avec un "Le code postal est inconnu/incorrect".
            'countryCode' => $this->resolveCountryCodeFromPostalCode($address->getPostalCode()) ?? 'FR',
            'email' => $customerData['email'],
            'mobileNumber' => $customerData['phone'],
        ];

        if (!empty($address->getAddressComplement())) {
            $addresseeAddress['line3'] = $this->normalizeAddressField($address->getAddressComplement());
        }

        if ($customerData['companyName'] !== null) {
            $addresseeAddress['companyName'] = $this->normalizeAddressField($customerData['companyName']);
        }

        $letter = [
            'service' => [
                'productCode' => 'DOM',
                'depositDate' => (new \DateTime())->format('Y-m-d'),
                'orderNumber' => $order->getOrderNumber(),
                'transportationAmount' => (float) ($order->getShippingCost() ?? 0),
                'totalAmount' => (float) $order->getTotalAmount(),
            ],
            'parcel' => [
                'weight' => $parcelWeightKg,
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
        ];

        // Andorre (hors FR malgré le produit DOM) : cf. buildDomesticParcelPayload.
        if ($addresseeAddress['countryCode'] !== 'FR') {
            $letter['customsDeclarations'] = $this->buildOrderCustomsContents($order);
        }

        return [
            'outputFormat' => [
                'x' => 0,
                'y' => 0,
                'outputPrintingType' => 'PDF_A4_300dpi',
            ],
            'letter' => $letter,
        ];
    }

    /**
     * Bloc customsDeclarations minimal pour une commande dont le produit est DOM
     * mais la destination hors France (cf. Andorre dans buildDomesticPayload).
     */
    private function buildOrderCustomsContents(Order $order): array
    {
        $articles = [];
        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            if (!$product) {
                continue;
            }

            $unitWeightGrams = $this->safeProductWeightGrams($product);

            $articles[] = [
                'description' => $this->getProductDescription($product),
                'quantity' => (int) $item->getQuantity(),
                'weight' => $this->gramsToKgCN23($unitWeightGrams),
                'value' => (float) $item->getUnitPrice(),
                'hsCode' => self::HS_CODE_PLANTS,
                'originCountry' => 'FR',
                'currency' => 'EUR',
            ];
        }

        return [
            'includeCustomsDeclarations' => 0,
            'contents' => [
                'article' => $articles,
                'category' => ['value' => 1],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // OM (CN23)
    // ------------------------------------------------------------------

    private function generateOMLabel(Order $order, DestinationZone $zone = DestinationZone::OUTRE_MER): array
    {
        $payload = $this->buildOMPayload($order, $zone);
        $productCode = $payload['letter']['service']['productCode'] ?? '?';

        $this->logger->info('Colissimo REST payload', [
            'product_code' => $productCode,
            'zone' => $zone->value,
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
                    $msg = $json['messages'][0]['messageContent'] ?? "Erreur Colissimo [$productCode]";
                    throw new \RuntimeException($msg);
                }

                if ($pdf !== null) {
                    return [
                        'success' => true,
                        'trackingNumber' => $json['labelResponse']['parcelNumber']
                            ?? $json['labelV2Response']['parcelNumber']
                            ?? $order->getOrderNumber(),
                        'labelPdf' => base64_encode($pdf),
                        'provider' => "Colissimo $productCode",
                        'rawData' => $json ?: ['pdfExtracted' => true],
                    ];
                }

                throw new \RuntimeException("Réponse multipart [$productCode] sans PDF exploitable.");
            }

            $data = $response->toArray(false);

            return [
                'success' => true,
                'trackingNumber' => $data['labelResponse']['parcelNumber'] ?? $data['labelV2Response']['parcelNumber'] ?? $order->getOrderNumber(),
                'labelPdf' => $data['labelResponse']['label'] ?? $data['labelV2Response']['label'] ?? null,
                'provider' => "Colissimo $productCode",
                'rawData' => $data,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Colissimo label generation failed', [
                'error' => $e->getMessage(),
                'product_code' => $productCode,
                'zone' => $zone->value,
                'order' => $order->getOrderNumber(),
            ]);

            throw new \RuntimeException("Erreur génération étiquette [$productCode]: " . $e->getMessage());
        }
    }

    /**
     * ✅ OM : cohérence arrondis Colissimo
     * - article.weight : kg (float, 3 décimales)
     * - parcel.weight  : kg (float, 2 décimales)
     * - parcel.weight = somme(articles déjà arrondis) + 0.10 kg (marge de sécurité), min 0.10kg
     */
    private function buildOMPayload(Order $order, DestinationZone $zone = DestinationZone::OUTRE_MER): array
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
            'lastName' => $this->normalizeAddressField($customerData['lastName']),
            'firstName' => $this->normalizeAddressField($customerData['firstName']),
            'line2' => $this->normalizeAddressField($address->getStreetAddress()),
            'zipCode' => $this->normalizePostalCode($address->getPostalCode()),
            'city' => $this->normalizeAddressField($address->getCity()),
            'countryCode' => $this->resolveAddresseeCountryCode($order),
            'email' => $customerData['email'],
            'mobileNumber' => $customerData['phone'],
        ];

        if ($customerData['companyName'] !== null) {
            $addresseeAddress['companyName'] = $this->normalizeAddressField($customerData['companyName']);
        }

        $countryCode = $addresseeAddress['countryCode'];
        if (!empty($address->getAddressComplement())) {
            $addresseeAddress['line3'] = $this->normalizeAddressField($address->getAddressComplement());
        }

        if (in_array($countryCode, ['US', 'CA'], true) && method_exists($address, 'getState') && $address->getState()) {
            $addresseeAddress['stateOrProvinceCode'] = strtoupper($address->getState());
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
                    'productCode' => $this->resolveProductCode($zone),
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
                        'eoriNumber' => $this->senderInfo['eori'] ?? '',
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
                'fields' => [
                    [
                        'key' => 'EORI',
                        'value' => $this->senderInfo['eori'],
                    ]
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
        $owner = $order->getOwner();

        // Compte réel (non invité) : ses données sont fiables et propres à cet
        // utilisateur, on les utilise en priorité.
        if ($owner && !$owner->isGuest()) {
            return [
                'email' => $owner->getEmail(),
                'firstName' => $owner->getFirstName(),
                'lastName' => $owner->getLastName(),
                'phone' => $owner->getPhone() ?? '0600000000',
                'companyName' => null,
            ];
        }

        // Invité : le guestPhone est un instantané propre à CETTE commande, stable
        // dans le temps — contrairement à owner.phone, qui pointe vers un User
        // invité partagé/mutable (réutilisé et écrasé par chaque nouvelle commande
        // passée avec le même email).
        if ($order->getGuestEmail()) {
            return [
                'email' => $order->getGuestEmail(),
                'firstName' => $order->getGuestFirstName(),
                'lastName' => $order->getGuestLastName(),
                'phone' => $order->getGuestPhone() ?? '0600000000',
                'companyName' => null,
            ];
        }

        // Dernier recours : compte invité partagé sans guestEmail renseigné.
        if ($owner) {
            return [
                'email' => $owner->getEmail(),
                'firstName' => $owner->getFirstName(),
                'lastName' => $owner->getLastName(),
                'phone' => $owner->getPhone() ?? '0600000000',
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
        $postalCode = strtoupper(trim((string) $postalCode));
        // Codes postaux purement numériques (FR, BE, DE, ES, IT…) : supprimer les espaces internes
        // Codes postaux alphanumériques (GB "CV34 4AB", CA "A1A 1A1", NL "1234 AB"…) : conserver l'espace interne
        if (preg_match('/^\d[\d\s]*\d$/', $postalCode) || preg_match('/^\d{5}$/', str_replace(' ', '', $postalCode))) {
            return preg_replace('/\s+/', '', $postalCode) ?? $postalCode;
        }
        // Supprimer uniquement les sauts de ligne et tabulations, pas les espaces internes
        return preg_replace('/[\t\n\r]/', '', $postalCode) ?? $postalCode;
    }

    /**
     * Translitère les caractères Unicode vers ASCII pour l'API Colissimo.
     * Ex: "Matāʻutu" → "Matautu", "Réunion" → "Reunion"
     */
    private function normalizeAddressField(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        // Remplace les caractères spéciaux polynésiens/océaniens avant NFD
        $value = strtr($value, [
            'ʻ' => '',  // U+02BB okina (hawaïen/polynésien)
            'ʼ' => '',  // U+02BC lettre modificatrice
            'ʾ' => '',  // U+02BE
            'ʿ' => '',  // U+02BF
            'ā' => 'a', 'Ā' => 'A',
            'ē' => 'e', 'Ē' => 'E',
            'ī' => 'i', 'Ī' => 'I',
            'ō' => 'o', 'Ō' => 'O',
            'ū' => 'u', 'Ū' => 'U',
        ]);
        // Normalise en forme NFD puis supprime les diacritiques
        $normalized = \Normalizer::normalize($value, \Normalizer::FORM_D) ?: $value;
        $normalized = preg_replace('/\p{Mn}/u', '', $normalized) ?? $normalized;
        // Translitère les caractères non-ASCII restants
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized) ?: $normalized;
        // Supprime tout caractère non imprimable et trim
        return trim(preg_replace('/[^\x20-\x7E]/', '', $normalized) ?? $normalized);
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

    /**
     * Territoires français utilisant des codes postaux à 5 chiffres.
     * Pour ces territoires uniquement, la résolution depuis le CP prime
     * (ex: CP 97100 stocké avec country=FR → résolve en GP).
     */
    private const FRENCH_POSTAL_TERRITORIES = [
        'FR', 'GP', 'MQ', 'GF', 'RE', 'YT', 'PM', 'BL', 'MF',
        'NC', 'PF', 'WF', 'TF', 'AD',
    ];

    private function resolveAddresseeCountryCode(Order $order): string
    {
        $address = $order->getShippingAddress();
        $storedCountry = strtoupper(trim((string) ($address?->getCountry() ?? '')));

        // Si le pays stocké est un ISO-2 valide hors territoire français,
        // on l'utilise directement sans tenter une résolution par CP
        // (évite que "43952" (US zip) soit résolu comme code postal FR)
        if (
            preg_match('/^[A-Z]{2}$/', $storedCountry)
            && !in_array($storedCountry, self::FRENCH_POSTAL_TERRITORIES, true)
        ) {
            return $storedCountry;
        }

        // Pour les adresses potentiellement françaises, résolution par CP
        // (gère les DOM-TOM stockés en country=FR ou avec leur propre code)
        $postalCode = $address ? $address->getPostalCode() : null;
        $fromPostal = $this->resolveCountryCodeFromPostalCode((string) $postalCode);
        if ($fromPostal) {
            return $fromPostal;
        }

        if (preg_match('/^[A-Z]{2}$/', $storedCountry)) {
            return $storedCountry;
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
     * Utilise Product::$weightGrams — le champ réellement renseigné et déjà utilisé
     * partout ailleurs (frais de port, colis, étiquettes). Product::$weight (float,
     * legacy) n'est jamais rempli en pratique, d'où un fallback 500g systématique
     * si on le lisait à la place.
     */
    private function safeProductWeightGrams($product): int
    {
        $grams = $product->getWeightGrams();

        if ($grams === null || $grams <= 0) {
            return 500;
        }

        return $grams;
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

        // strpos (first occurrence) to extract only the label PDF,
        // not a blob spanning both label and CN23
        $pdfEnd = strpos($content, '%%EOF', $pdfStart);
        if ($pdfEnd === false || $pdfEnd <= $pdfStart) {
            return null;
        }

        return substr($content, $pdfStart, $pdfEnd - $pdfStart + 5);
    }

    private function extractCn23FromMultipart(string $content): ?string
    {
        // Locate the end of the first PDF (label)
        $firstPdfStart = strpos($content, '%PDF');
        if ($firstPdfStart === false) {
            return null;
        }

        $firstPdfEnd = strpos($content, '%%EOF', $firstPdfStart);
        if ($firstPdfEnd === false) {
            return null;
        }

        // The CN23 is the second PDF in the multipart body
        $cn23Start = strpos($content, '%PDF', $firstPdfEnd + 5);
        if ($cn23Start === false) {
            return null;
        }

        $cn23End = strpos($content, '%%EOF', $cn23Start);
        if ($cn23End === false || $cn23End <= $cn23Start) {
            return null;
        }

        return substr($content, $cn23Start, $cn23End - $cn23Start + 5);
    }

    private function extractJsonFromMultipart(string $content): array
    {
        // The JSON part always comes before the first PDF in the multipart body.
        // Restricting the search window avoids strrpos() picking up '}' bytes
        // inside the binary PDF content and producing malformed JSON.
        $pdfStart  = strpos($content, '%PDF');
        $searchIn  = $pdfStart !== false ? substr($content, 0, $pdfStart) : $content;

        $start = strpos($searchIn, '{');
        $end   = strrpos($searchIn, '}');

        if ($start === false || $end === false || $end <= $start) {
            $this->logger->warning('Colissimo multipart: no valid JSON found before PDF', [
                'content_length' => strlen($content),
                'pdf_offset'     => $pdfStart,
            ]);
            return [];
        }

        $jsonStr = substr($searchIn, $start, $end - $start + 1);
        $decoded = json_decode($jsonStr, true);

        if (!is_array($decoded)) {
            $this->logger->warning('Colissimo multipart: JSON decode failed', [
                'json_sample' => substr($jsonStr, 0, 200),
            ]);
            return [];
        }

        return $decoded;
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