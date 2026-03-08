<?php

namespace App\Shipping\Service\LabelGenerator;

use App\Media\Service\CloudinaryService;
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
        private CloudinaryService   $cloudinary,
        private LoggerInterface     $logger,
    ) {}

    public function generateLabelForParcel(Parcel $parcel): LabelGenerationResult
    {
        try {
            $this->logger->info('Generating Colissimo label for parcel', [
                'parcel_id' => $parcel->getId()->toRfc4122(),
                'order_id' => $parcel->getOrder()->getId()->toRfc4122(),
            ]);

            $apiResult = $this->colissimoApi->generateLabelForParcel($parcel);

            if (!($apiResult['success'] ?? false)) {
                return new LabelGenerationResult(
                    success: false,
                    error: $apiResult['error'] ?? 'Colissimo API returned failure'
                );
            }

            $trackingNumber = $apiResult['trackingNumber'] ?? null;
            $labelPdfBase64 = $apiResult['labelPdf'] ?? null;
            $cn23PdfBase64  = $apiResult['cn23Pdf'] ?? null;

            $orderNumber = $parcel->getOrder()->getOrderNumber();
            $parcelNum   = $parcel->getParcelNumber();

            $labelUrl = $this->uploadPdfToCloudinary(
                $labelPdfBase64,
                sprintf('%s-P%d-label', $orderNumber, $parcelNum),
                'khamareo/labels'
            );

            $cn23Url = $cn23PdfBase64
                ? $this->uploadPdfToCloudinary(
                    $cn23PdfBase64,
                    sprintf('%s-P%d-cn23', $orderNumber, $parcelNum),
                    'khamareo/labels'
                )
                : null;

            return new LabelGenerationResult(
                success: true,
                trackingNumber: $trackingNumber,
                labelUrl: $labelUrl,
                cn23Url: $cn23Url,
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

    private function uploadPdfToCloudinary(?string $base64Pdf, string $publicId, string $folder): ?string
    {
        if (!$base64Pdf) {
            return null;
        }

        $tmpPath = sys_get_temp_dir() . '/colissimo_' . uniqid('', true) . '.pdf';

        try {
            file_put_contents($tmpPath, base64_decode($base64Pdf));

            $result = $this->cloudinary->uploadImage($tmpPath, [
                'folder'        => $folder,
                'resource_type' => 'raw',
                'public_id'     => $publicId,
                'tags'          => ['label', 'colissimo'],
                'overwrite'     => true,
            ]);

            if (!($result['success'] ?? false)) {
                $this->logger->warning('Colissimo label upload to Cloudinary failed (soft-fail)', [
                    'public_id' => $publicId,
                    'error' => $result['error'] ?? 'unknown',
                ]);
                return null;
            }

            return $result['url'] ?? null;

        } catch (\Exception $e) {
            $this->logger->warning('Colissimo label upload exception (soft-fail)', [
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
}
