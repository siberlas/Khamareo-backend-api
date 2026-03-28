<?php

namespace App\Shipping\DTO\MondialRelay;

readonly class MondialRelayLabelDTO
{
    public function __construct(
        public string $shipmentNumber,
        public string $labelUrl,
        public string $rawXmlResponse,
    ) {}
}
