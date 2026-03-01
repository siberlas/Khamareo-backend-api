<?php
// MODIFICATIONS dans src/Shipping/Service/LabelGenerator/LabelGenerationResult.php

namespace App\Shipping\Service\LabelGenerator;

readonly class LabelGenerationResult
{
    public function __construct(
        public bool $success,
        public ?string $trackingNumber = null,
        public ?string $labelUrl = null,
        public ?string $cn23Url = null,  // ✅ AJOUT
        public ?array $rawData = null,
        public ?string $error = null,
    ) {}

    public static function success(
        string $trackingNumber,
        string $labelUrl,
        ?string $cn23Url = null,  // ✅ AJOUT
        array $rawData = []
    ): self {
        return new self(
            success: true,
            trackingNumber: $trackingNumber,
            labelUrl: $labelUrl,
            cn23Url: $cn23Url,  // ✅ AJOUT
            rawData: $rawData,
        );
    }

    public static function failure(string $error): self
    {
        return new self(
            success: false,
            error: $error,
        );
    }
}