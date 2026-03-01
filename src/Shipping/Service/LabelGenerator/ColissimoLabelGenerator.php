<?php

namespace App\Shipping\Service\LabelGenerator;

use App\Shipping\Entity\Parcel;
use App\Shipping\Service\ColissimoApiService;
use Psr\Log\LoggerInterface;

/**
 * Générateur d'étiquettes pour Colissimo
 */
class ColissimoLabelGenerator implements LabelGeneratorInterface
{
    public function __construct(
        private ColissimoApiService $colissimoApi,
        private LoggerInterface $logger,
    ) {}
    
    public function generateLabelForParcel(Parcel $parcel): LabelGenerationResult
    {
        try {
            $this->logger->info('Generating Colissimo label for parcel', [
                'parcel_id' => $parcel->getId()->toRfc4122(),
                'order_id' => $parcel->getOrder()->getId()->toRfc4122(),
            ]);
            
            $result = $this->colissimoApi->generateLabelForParcel($parcel);
            
            return new LabelGenerationResult(
                success: $result['success'] ?? true,
                trackingNumber: $result['trackingNumber'] ?? null,
                labelUrl: $result['labelUrl'] ?? null,
                cn23Url: $result['cn23Url'] ?? null,
                rawData: $result['rawData'] ?? null,
            );
            
        } catch (\Exception $e) {
            $this->logger->error('Colissimo label generation failed', [
                'parcel_id' => $parcel->getId()->toRfc4122(),
                'error' => $e->getMessage(),
            ]);
            
            return new LabelGenerationResult(
                success: false,
                error: $e->getMessage(),
            );
        }
    }
    
    public function supports(string $carrierCode): bool
    {
        return in_array(strtolower($carrierCode), ['colissimo', 'coliposte']);
    }
}