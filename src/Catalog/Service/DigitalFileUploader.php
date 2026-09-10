<?php

namespace App\Catalog\Service;

use App\Catalog\Entity\Product;
use App\Media\Service\CloudinaryService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Envoie le PDF source d'un livre numérique sur Cloudinary (raw / authenticated)
 * et le rattache au produit. Utilisé aussi bien au CRUD produit (formulaire
 * multipart) que par l'endpoint dédié de remplacement.
 */
class DigitalFileUploader
{
    public const MAX_SIZE_BYTES = 50 * 1024 * 1024; // 50 Mo
    private const FOLDER = 'khamareo/ebooks/source';

    public function __construct(
        private readonly CloudinaryService $cloudinary,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return string|null message d'erreur, ou null si succès */
    public function validate(UploadedFile $file): ?string
    {
        $mime = $file->getMimeType();
        $ext = strtolower($file->getClientOriginalExtension());
        if ($mime !== 'application/pdf' && $ext !== 'pdf') {
            return 'Seuls les fichiers PDF sont acceptés pour un livre numérique.';
        }
        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            return 'Le fichier PDF dépasse la limite de 50 Mo.';
        }
        return null;
    }

    /** @return string|null message d'erreur, ou null si succès */
    public function attach(Product $product, UploadedFile $file): ?string
    {
        if ($error = $this->validate($file)) {
            return $error;
        }

        $result = $this->cloudinary->uploadRawAuthenticated($file->getPathname(), self::FOLDER);
        if (!($result['success'] ?? false)) {
            $this->logger->error('Échec upload ebook Cloudinary', [
                'product' => (string) $product->getId(),
                'error' => $result['error'] ?? '?',
            ]);
            return "L'envoi du fichier PDF a échoué. Réessayez.";
        }

        $previous = $product->getDigitalFilePublicId();
        if ($previous !== null && $previous !== $result['publicId']) {
            $this->cloudinary->deleteRaw($previous);
        }

        $product->setDigitalFilePublicId($result['publicId']);
        $product->setDigitalFileOriginalName($file->getClientOriginalName());
        $product->setDigitalFileSizeBytes($result['bytes'] ?? $file->getSize());

        return null;
    }

    public function detach(Product $product): void
    {
        if ($product->getDigitalFilePublicId() !== null) {
            $this->cloudinary->deleteRaw($product->getDigitalFilePublicId());
        }
        $product->setDigitalFilePublicId(null);
        $product->setDigitalFileOriginalName(null);
        $product->setDigitalFileSizeBytes(null);
    }
}
