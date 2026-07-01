<?php

namespace App\Shipping\Service\LabelGenerator;

use App\Media\Service\CloudinaryService;
use App\Shipping\DTO\MondialRelay\MondialRelayAddressDTO;
use App\Shipping\DTO\MondialRelay\MondialRelayShipmentDTO;
use App\Shipping\Entity\Parcel;
use App\Shipping\Enum\MondialRelayMode;
use App\Shipping\Exception\MondialRelayApiException;
use App\Shipping\Service\MondialRelayApiService;
use Psr\Log\LoggerInterface;

/**
 * Generateur d'etiquettes pour Mondial Relay
 */
class MondialRelayLabelGenerator implements LabelGeneratorInterface
{
    public function __construct(
        private MondialRelayApiService $mondialRelayApi,
        private CloudinaryService      $cloudinary,
        private LoggerInterface        $logger,
        private string                 $senderCompany,
        private string                 $senderFirstname,
        private string                 $senderLastname,
        private string                 $senderAddress,
        private string                 $senderZipcode,
        private string                 $senderCity,
        private string                 $senderCountry,
        private string                 $senderEmail,
        private string                 $senderPhone,
        // Point relais où le vendeur dépose ses colis (ex: "FR-12345").
        // Requis pour les livraisons 24R : sans lui, MR ne peut pas calculer le
        // plan de tri entre deux relay points et retourne l'erreur 10055.
        private string                 $collectionRelayId = '',
    ) {}

    public function generateLabelForParcel(Parcel $parcel): LabelGenerationResult
    {
        try {
            $order = $parcel->getOrder();

            $this->logger->info('Generating Mondial Relay label for parcel', [
                'parcel_id'  => $parcel->getId()->toRfc4122(),
                'order_id'   => $order->getId()->toRfc4122(),
                'order_number' => $order->getOrderNumber(),
            ]);

            $shippingAddress = $order->getShippingAddress();
            if (!$shippingAddress) {
                return new LabelGenerationResult(
                    success: false,
                    error: 'No shipping address on order',
                );
            }

            // Build sender address
            [$senderHouseNo, $senderStreet] = $this->splitStreetAddress($this->senderAddress);
            $sender = new MondialRelayAddressDTO(
                title: '',
                firstname: mb_substr($this->senderFirstname, 0, 20),
                lastname: mb_substr($this->senderLastname, 0, 20),
                addressAdd1: mb_substr($this->senderCompany, 0, 32),
                streetname: mb_substr($senderStreet, 0, 32),
                houseNo: mb_substr($senderHouseNo, 0, 8),
                postcode: $this->senderZipcode,
                city: mb_substr($this->senderCity, 0, 25),
                countryCode: $this->senderCountry,
                email: $this->senderEmail,
                phoneNo: $this->senderPhone,
            );

            // Build recipient address
            [$recipientHouseNo, $recipientStreet] = $this->splitStreetAddress($shippingAddress->getStreetAddress());
            $recipientEmail = $order->getOwner()?->getEmail() ?? $order->getGuestEmail() ?? '';
            $recipientPhone = $shippingAddress->getPhone()
                ?: ($order->getOwner()?->getPhone()
                ?: ($order->getGuestPhone()
                ?: ''));

            $isRelayPointCheck = $shippingAddress->isRelayPoint() || !empty($order->getRelayPointId());
            if (!$isRelayPointCheck && empty($recipientPhone)) {
                return new LabelGenerationResult(
                    success: false,
                    error: 'Un numéro de téléphone est requis pour la livraison à domicile Mondial Relay. Veuillez mettre à jour l\'adresse de livraison.',
                );
            }

            $recipient = new MondialRelayAddressDTO(
                title: $shippingAddress->getCivility() ?? '',
                firstname: mb_substr($shippingAddress->getFirstName() ?? '', 0, 20),
                lastname: mb_substr($shippingAddress->getLastName() ?? '', 0, 20),
                addressAdd1: '', // Complément d'adresse (vide)
                addressAdd2: mb_substr($shippingAddress->getCompanyName() ?? '', 0, 32),
                streetname: mb_substr($recipientStreet, 0, 32),
                houseNo: mb_substr($recipientHouseNo, 0, 8),
                postcode: $shippingAddress->getPostalCode(),
                city: mb_substr($shippingAddress->getCity(), 0, 25),
                countryCode: $this->resolveCountryCode($shippingAddress->getCountry()),
                phoneNo: $recipientPhone,
                mobileNo: $recipientPhone,
                email: $recipientEmail,
            );

            // Determine delivery mode (reuse $isRelayPointCheck computed above)
            $isRelayPoint = $isRelayPointCheck;
            $deliveryMode = $isRelayPoint ? MondialRelayMode::RELAY_POINT : MondialRelayMode::HOME;

            // Format relay point location
            $deliveryLocation = '';
            $relayPointId = $order->getRelayPointId() ?? $shippingAddress->getRelayPointId();
            if ($isRelayPoint && $relayPointId) {
                // Format as "FR-XXXXX" if not already formatted
                if (!str_contains($relayPointId, '-')) {
                    $deliveryLocation = $this->resolveCountryCode($shippingAddress->getCountry()) . '-' . $relayPointId;
                } else {
                    $deliveryLocation = $relayPointId;
                }
            }

            // Weight: use parcel weight or default to 500g
            $weightGrams = $parcel->getWeightGrams() ?? 500;
            if ($weightGrams < 10) {
                $weightGrams = 10;
            }

            // REL = vendeur dépose le colis en Point Relais (mode standard pour un e-commerce).
            // CCC = collecte entrepôt (Mondial Relay vient chercher chez le vendeur) — non applicable ici.
            // CollectionLocation = relay de dépôt du vendeur (MONDIAL_RELAY_COLLECTION_RELAY_ID).
            $collectionLocation = '';
            if (!empty($this->collectionRelayId)) {
                $collectionLocation = str_contains($this->collectionRelayId, '-')
                    ? $this->collectionRelayId
                    : $this->resolveCountryCode($this->senderCountry) . '-' . $this->collectionRelayId;
            }

            $this->logger->info('Mondial Relay label request params', [
                'order_number'               => $order->getOrderNumber(),
                'delivery_mode'              => $deliveryMode,
                'relay_destination_client'   => $relayPointId ?? '(aucun)',
                'delivery_location_xml'      => $deliveryLocation ?: '(vide)',
                'collection_mode'            => MondialRelayMode::COLLECTION_RELAY,
                'relay_collecte_expediteur'  => $collectionLocation ?: '(vide — MONDIAL_RELAY_COLLECTION_RELAY_ID non configuré)',
                'weight_grams'               => $weightGrams,
            ]);

            $dto = new MondialRelayShipmentDTO(
                sender: $sender,
                recipient: $recipient,
                weightGrams: $weightGrams,
                deliveryMode: $deliveryMode,
                deliveryLocation: $deliveryLocation,
                collectionMode: MondialRelayMode::COLLECTION_RELAY,
                collectionLocation: $collectionLocation,
                orderNo: substr($order->getOrderNumber() ?? '', 0, 15),
                customerNo: '',
                parcelContent: 'Produits naturels',
                outputFormat: 'A4',
                outputType: 'PdfUrl',
            );

            $labelResult = $this->mondialRelayApi->createLabel($dto);

            // Upload label PDF to Cloudinary
            $orderNumber = $order->getOrderNumber();
            $parcelNum = $parcel->getParcelNumber();
            $labelUrl = $this->uploadLabelPdfFromUrl(
                $labelResult->labelUrl,
                sprintf('%s-P%d-label-mr', $orderNumber, $parcelNum),
                'khamareo/labels'
            );

            return new LabelGenerationResult(
                success: true,
                trackingNumber: $labelResult->shipmentNumber,
                labelUrl: $labelUrl ?? $labelResult->labelUrl,
            );

        } catch (MondialRelayApiException $e) {
            $this->logger->warning('Mondial Relay API error during label generation', [
                'parcel_id'      => $parcel->getId()->toRfc4122(),
                'order_number'   => $parcel->getOrder()->getOrderNumber(),
                'relay_point_id' => $parcel->getOrder()->getRelayPointId()
                                    ?? $parcel->getOrder()->getShippingAddress()?->getRelayPointId()
                                    ?? '(aucun)',
                'error_codes'    => $e->getErrorCodes(),
                'message'        => $e->getMessage(),
            ]);
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Mondial Relay label generation failed', [
                'parcel_id'      => $parcel->getId()->toRfc4122(),
                'order_number'   => $parcel->getOrder()->getOrderNumber(),
                'relay_point_id' => $parcel->getOrder()->getRelayPointId()
                                    ?? $parcel->getOrder()->getShippingAddress()?->getRelayPointId()
                                    ?? '(aucun)',
                'error'          => $e->getMessage(),
            ]);

            return new LabelGenerationResult(
                success: false,
                error: $e->getMessage(),
            );
        }
    }

    public function supports(string $carrierCode): bool
    {
        return in_array(strtolower($carrierCode), ['mondial_relay', 'mondialrelay', 'mondial-relay']);
    }

    /**
     * Download label PDF from URL and upload to Cloudinary
     */
    private function uploadLabelPdfFromUrl(string $pdfUrl, string $publicId, string $folder): ?string
    {
        if (empty($pdfUrl)) {
            return null;
        }

        $tmpPath = sys_get_temp_dir() . '/mondial_relay_' . uniqid('', true) . '.pdf';

        try {
            $pdfContent = file_get_contents($pdfUrl);
            if ($pdfContent === false) {
                $this->logger->warning('Failed to download Mondial Relay label PDF', [
                    'url' => $pdfUrl,
                ]);
                return null;
            }

            file_put_contents($tmpPath, $pdfContent);

            $result = $this->cloudinary->uploadImage($tmpPath, [
                'folder'        => $folder,
                'resource_type' => 'raw',
                'public_id'     => $publicId,
                'tags'          => ['label', 'mondial_relay'],
                'overwrite'     => true,
            ]);

            if (!($result['success'] ?? false)) {
                $this->logger->warning('Mondial Relay label upload to Cloudinary failed (soft-fail)', [
                    'public_id' => $publicId,
                    'error' => $result['error'] ?? 'unknown',
                ]);
                return null;
            }

            return $result['url'] ?? null;

        } catch (\Exception $e) {
            $this->logger->warning('Mondial Relay label upload exception (soft-fail)', [
                'public_id' => $publicId,
                'error' => $e->getMessage(),
            ]);
            return null;
        } finally {
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
        }
    }

    /**
     * Split "4 rue Claude Pernes" into ["4", "rue Claude Pernes"]
     * @return array{0: string, 1: string} [houseNo, streetname]
     */
    private function splitStreetAddress(string $address): array
    {
        $address = trim($address);
        if (preg_match('/^(\d+\s*(?:bis|ter)?)\s+(.+)$/i', $address, $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        return ['', $address];
    }

    /**
     * Resolve country name to ISO 2-letter code
     */
    private function resolveCountryCode(string $country): string
    {
        // If already a 2-letter code
        if (strlen($country) === 2) {
            return strtoupper($country);
        }

        $map = [
            'france' => 'FR',
            'belgique' => 'BE',
            'belgium' => 'BE',
            'luxembourg' => 'LU',
            'espagne' => 'ES',
            'spain' => 'ES',
            'portugal' => 'PT',
            'pays-bas' => 'NL',
            'netherlands' => 'NL',
            'allemagne' => 'DE',
            'germany' => 'DE',
            'italie' => 'IT',
            'italy' => 'IT',
            'autriche' => 'AT',
            'austria' => 'AT',
        ];

        return $map[strtolower(trim($country))] ?? strtoupper(substr($country, 0, 2));
    }
}
