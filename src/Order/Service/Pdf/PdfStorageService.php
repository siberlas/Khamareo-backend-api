<?php

namespace App\Order\Service\Pdf;

use App\Media\Service\CloudinaryService;
use App\Order\Service\Pdf\Generator\PdfGenerationResult;
use Psr\Log\LoggerInterface;

/**
 * Upload les PDFs générés vers Cloudinary (resource_type: raw).
 * Soft-fail : si l'upload échoue, retourne le résultat sans URL Cloudinary.
 */
class PdfStorageService
{
    public function __construct(
        private CloudinaryService $cloudinary,
        private LoggerInterface $logger,
        private string $tempDir,    // '%kernel.project_dir%/var/documents'
    ) {}

    /**
     * @param string   $folder Ex: 'khamareo/documents/purchase-order'
     * @param string[] $tags
     */
    public function store(
        PdfGenerationResult $result,
        string $folder,
        array $tags = []
    ): PdfGenerationResult {

        if (!$result->success || !$result->pdfContent) {
            return $result;
        }

        $tmpPath = $this->tempDir . '/' . uniqid('pdf_', true) . '.pdf';

        try {
            $this->ensureTempDir();
            file_put_contents($tmpPath, $result->pdfContent);

            $uploadResult = $this->cloudinary->uploadImage($tmpPath, [
                'folder'        => $folder,
                'resource_type' => 'raw',       // Obligatoire pour les PDFs
                'public_id'     => pathinfo($result->filename, PATHINFO_FILENAME),
                'tags'          => array_merge(['document', 'pdf'], $tags),
                'overwrite'     => true,
            ]);

            if (!$uploadResult['success']) {
                $this->logger->error('Pdf upload to Cloudinary failed', [
                    'filename' => $result->filename,
                    'error'    => $uploadResult['error'] ?? 'unknown',
                ]);

                return $result;  // Soft fail : le PDF est généré, juste pas stocké
            }

            $this->logger->info('Pdf uploaded to Cloudinary', [
                'filename' => $result->filename,
                'asset_id' => $uploadResult['asset_id'],
                'folder'   => $folder,
            ]);

            return $result->withCloudinaryData(
                url:      $uploadResult['secure_url'],
                assetId:  $uploadResult['asset_id'],
                publicId: $uploadResult['public_id'],
            );

        } catch (\Throwable $e) {
            $this->logger->error('PdfStorageService: unexpected error', [
                'filename' => $result->filename,
                'error'    => $e->getMessage(),
            ]);

            return $result;

        } finally {
            // Nettoyage dans tous les cas (succès ou erreur)
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
        }
    }

    private function ensureTempDir(): void
    {
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }
}