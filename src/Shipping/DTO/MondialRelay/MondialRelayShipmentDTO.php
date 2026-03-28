<?php

namespace App\Shipping\DTO\MondialRelay;

readonly class MondialRelayShipmentDTO
{
    public function __construct(
        public MondialRelayAddressDTO $sender,
        public MondialRelayAddressDTO $recipient,
        public int                    $weightGrams,          // Min 10
        public string                 $deliveryMode,         // "24R","24L","HOM","HOC","LCC"
        public string                 $deliveryLocation = '', // "FR-XXXXX" for relay points
        public string                 $collectionMode = 'CCC',
        public string                 $collectionLocation = '',
        public string                 $orderNo = '',         // Max 15 chars
        public string                 $customerNo = '',      // Max 9 chars
        public string                 $parcelContent = '',   // Max 40 chars
        public string                 $outputFormat = 'A4',
        public string                 $outputType = 'PdfUrl',
    ) {}
}
