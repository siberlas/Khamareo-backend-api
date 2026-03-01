<?php

namespace App\Admin\Controller;

use App\Media\Service\CloudinaryService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
#[Route('/api/admin/media', name: 'admin_media_')]
class MediaUploadController extends AbstractController
{
    public function __construct(
        private CloudinaryService $cloudinaryService,
        private LoggerInterface $logger
    ) {}

    /**
     * Upload une image de produit vers Cloudinary
     * 
     * POST /api/admin/media/upload
     * 
     * FormData:
     *   file: Image file (JPG, PNG, WEBP, GIF)
     * 
     * Response:
     * {
     *   "success": true,
     *   "url": "https://res.cloudinary.com/.../image.jpg",
     *   "secureUrl": "https://res.cloudinary.com/.../image.jpg",
     *   "assetId": "abc123...",
     *   "publicId": "products/xyz789",
     *   "width": 1920,
     *   "height": 1080,
     *   "format": "jpg",
     *   "bytes": 245678,
     *   "thumbnailUrl": "https://..."
     * }
     */
    #[Route('/upload', name: 'upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');

        if (!$file) {
            return $this->json([
                'success' => false,
                'error' => 'Aucun fichier fourni'
            ], 400);
        }

        // Validation du fichier
        $allowedMimeTypes = [
            'image/jpeg',
            'image/jpg', 
            'image/png',
            'image/webp',
            'image/gif'
        ];

        if (!in_array($file->getMimeType(), $allowedMimeTypes, true)) {
            return $this->json([
                'success' => false,
                'error' => 'Type de fichier non autorisé. Formats acceptés : JPG, PNG, WEBP, GIF'
            ], 400);
        }

        // Taille max : 5 MB
        $maxSize = 5 * 1024 * 1024; // 5 MB en bytes
        if ($file->getSize() > $maxSize) {
            return $this->json([
                'success' => false,
                'error' => 'Fichier trop volumineux. Taille maximum : 5 MB'
            ], 400);
        }

        try {
            // Options d'upload Cloudinary
            $uploadOptions = [
                'asset_folder' => '/khamareo/products',  // Dossier pour les produits
                'tags' => ['product'],
                'resource_type' => 'image',
                'overwrite' => false,
                'unique_filename' => true,
            ];

            // Upload via CloudinaryService
            $result = $this->cloudinaryService->uploadImage(
                $file->getPathname(),
                $uploadOptions
            );

            if (!$result['success']) {
                return $this->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Erreur lors de l\'upload'
                ], 500);
            }

            // Générer l'URL du thumbnail
            $thumbnailUrl = $this->cloudinaryService->generateThumbnailUrl(
                $result['publicId'],
                300,
                300
            );

            $this->logger->info('Product image uploaded', [
                'asset_id' => $result['assetId'],
                'public_id' => $result['publicId'],
                'size' => $result['bytes'],
            ]);

            return $this->json([
                'success' => true,
                'url' => $result['url'],
                'secureUrl' => $result['url'],
                'assetId' => $result['assetId'],
                'publicId' => $result['publicId'],
                'width' => $result['width'] ?? null,
                'height' => $result['height'] ?? null,
                'format' => $result['format'],
                'bytes' => $result['bytes'],
                'thumbnailUrl' => $thumbnailUrl,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Product image upload failed', [
                'error' => $e->getMessage(),
                'file' => $file->getClientOriginalName(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de l\'upload : ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload multiple images de produits
     * 
     * POST /api/admin/media/upload-multiple
     * 
     * FormData:
     *   files[]: Multiple image files
     * 
     * Response:
     * {
     *   "success": true,
     *   "uploaded": 3,
     *   "failed": 0,
     *   "results": [...],
     *   "errors": []
     * }
     */
    #[Route('/upload-multiple', name: 'upload_multiple', methods: ['POST'])]
    public function uploadMultiple(Request $request): JsonResponse
    {
        $files = $request->files->get('files', []);

        if (empty($files)) {
            return $this->json([
                'success' => false,
                'error' => 'Aucun fichier fourni'
            ], 400);
        }

        $results = [];
        $errors = [];

        foreach ($files as $index => $file) {
            if (!$file instanceof UploadedFile) {
                $errors[] = [
                    'index' => $index,
                    'error' => 'Fichier invalide'
                ];
                continue;
            }

            // Validation
            $allowedMimeTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
            if (!in_array($file->getMimeType(), $allowedMimeTypes, true)) {
                $errors[] = [
                    'index' => $index,
                    'filename' => $file->getClientOriginalName(),
                    'error' => 'Type de fichier non autorisé'
                ];
                continue;
            }

            if ($file->getSize() > 5 * 1024 * 1024) {
                $errors[] = [
                    'index' => $index,
                    'filename' => $file->getClientOriginalName(),
                    'error' => 'Fichier trop volumineux (max 5 MB)'
                ];
                continue;
            }

            try {
                $uploadOptions = [
                    'asset_folder' => 'khamareo/products',
                    'tags' => ['product'],
                    'resource_type' => 'image',
                ];

                $result = $this->cloudinaryService->uploadImage(
                    $file->getPathname(),
                    $uploadOptions
                );

                if ($result['success']) {
                    $thumbnailUrl = $this->cloudinaryService->generateThumbnailUrl(
                        $result['publicId'],
                        300,
                        300
                    );

                    $results[] = [
                        'url' => $result['url'],
                        'secureUrl' => $result['url'],
                        'assetId' => $result['assetId'],
                        'publicId' => $result['publicId'],
                        'width' => $result['width'] ?? null,
                        'height' => $result['height'] ?? null,
                        'format' => $result['format'],
                        'bytes' => $result['bytes'],
                        'thumbnailUrl' => $thumbnailUrl,
                    ];
                } else {
                    $errors[] = [
                        'index' => $index,
                        'filename' => $file->getClientOriginalName(),
                        'error' => $result['error'] ?? 'Erreur inconnue'
                    ];
                }

            } catch (\Exception $e) {
                $errors[] = [
                    'index' => $index,
                    'filename' => $file->getClientOriginalName(),
                    'error' => $e->getMessage()
                ];
            }
        }

        return $this->json([
            'success' => empty($errors),
            'uploaded' => count($results),
            'failed' => count($errors),
            'results' => $results,
            'errors' => $errors,
        ]);
    }

    /**
     * Supprimer une image de Cloudinary
     * 
     * DELETE /api/admin/media/{assetId}
     * 
     * Response:
     * {
     *   "success": true,
     *   "message": "Image supprimée avec succès"
     * }
     */
    #[Route('/{assetId}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $assetId): JsonResponse
    {
        try {
            $result = $this->cloudinaryService->deleteImage($assetId);

            if ($result['success']) {
                $this->logger->info('Product image deleted', [
                    'asset_id' => $assetId,
                ]);

                return $this->json([
                    'success' => true,
                    'message' => 'Image supprimée avec succès'
                ]);
            }

            return $this->json([
                'success' => false,
                'error' => $result['message'] ?? 'Impossible de supprimer l\'image'
            ], 400);

        } catch (\Exception $e) {
            $this->logger->error('Product image deletion failed', [
                'asset_id' => $assetId,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la suppression : ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Lister les images produits depuis Cloudinary
     * 
     * GET /api/admin/media/products
     * 
     * Query params:
     *   ?limit=50 (optionnel, défaut: 100)
     * 
     * Response:
     * {
     *   "success": true,
     *   "images": [...],
     *   "total": 45
     * }
     */
    #[Route('/products', name: 'list', methods: ['GET'])]
    public function listProducts(Request $request): JsonResponse
    {
        $limit = $request->query->getInt('limit', 100);

        try {
            $result = $this->cloudinaryService->listImages(
                'khamareo/products',  // Dossier
                $limit,
                ['product']  // Tags
            );

            return $this->json($result);

        } catch (\Exception $e) {
            $this->logger->error('Product images list failed', [
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la récupération des images',
                'images' => [],
                'total' => 0
            ], 500);
        }
    }

    /**
     * Obtenir les détails d'une image
     * 
     * GET /api/admin/media/detail/{assetId}
     * 
     * Response:
     * {
     *   "success": true,
     *   "image": {
     *     "assetId": "...",
     *     "publicId": "products/...",
     *     "url": "https://...",
     *     "width": 1920,
     *     "height": 1080,
     *     ...
     *   }
     * }
     */
    #[Route('/detail/{assetId}', name: 'detail', methods: ['GET'])]
    public function getDetail(string $assetId): JsonResponse
    {
        try {
            $result = $this->cloudinaryService->getImageByAssetId($assetId);

            if ($result['success']) {
                return $this->json($result);
            }

            return $this->json([
                'success' => false,
                'error' => 'Image introuvable'
            ], 404);

        } catch (\Exception $e) {
            $this->logger->error('Get image detail failed', [
                'asset_id' => $assetId,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la récupération des détails'
            ], 500);
        }
    }
}