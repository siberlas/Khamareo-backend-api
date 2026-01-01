<?php
// src/Controller/MediaController.php

namespace App\Media\Controller;

use App\Entity\Media;
use App\Media\Repository\MediaRepository;
use App\Media\Service\MediaService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
#[Route('/api/media')]
#[IsGranted('ROLE_ADMIN')]
class MediaController extends AbstractController
{
    public function __construct(
        private MediaService $mediaService,
        private MediaRepository $mediaRepository,
        private EntityManagerInterface $em,
        private Security $security
    ) {}

    /**
     * Liste tous les médias avec filtres optionnels
     * GET /api/media?folder=khamareo/products&tags[]=product&search=shea
     */
    #[Route('', name: 'media_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $folder = $request->query->get('folder');
        $tags = $request->query->all('tags') ?: null;
        $search = $request->query->get('search');
        $limit = (int) $request->query->get('limit', 50);

        $medias = $this->mediaService->search($search, $tags, $folder, $limit);

        return $this->json([
            'success' => true,
            'count' => count($medias),
            'medias' => $medias,
        ], 200, [], ['groups' => ['media:read']]);
    }

     /**
     * Statistiques globales
     * GET /api/media/stats
     */
    #[Route('/stats', name: 'media_stats', methods: ['GET'],priority: 10)]
    public function stats(): JsonResponse
    {
        return $this->json([
            'success' => true,
            'stats' => $this->mediaService->getStats(),
        ]);
    }

    /**
     * Récupère un média par ID
     * GET /api/media/{id}
     */
    #[Route('/{id}', name: 'media_get', methods: ['GET'], priority: 0)]
    public function get(Media $media): JsonResponse
    {
        return $this->json([
            'success' => true,
            'media' => $media,
        ], 200, [], ['groups' => ['media:read']]);
    }

    /**
     * Upload un ou plusieurs fichiers
     * POST /api/media/upload
     * Content-Type: multipart/form-data
     * Body: files[], folder, tags[], altText
     */
    #[Route('/upload', name: 'media_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        /** @var UploadedFile[] $files */
        $files = $request->files->get('files', []);
        
        if (empty($files)) {
            throw new BadRequestHttpException('Aucun fichier uploadé');
        }

        // Si un seul fichier est envoyé (pas dans un array)
        if (!is_array($files)) {
            $files = [$files];
        }

        $folder = $request->request->get('folder', 'khamareo');
        $tags = $request->request->all('tags') ?: [];
        $altText = $request->request->get('altText');
        
        $uploadedMedias = [];
        $errors = [];

        foreach ($files as $index => $file) {
            if (!$file instanceof UploadedFile) {
                $errors[] = "Fichier #{$index} invalide";
                continue;
            }

            try {
                $media = $this->mediaService->uploadImage(
                    $file,
                    $folder,
                    $tags,
                    $this->security->getUser(),
                    $altText
                );
                
                $uploadedMedias[] = $media;

            } catch (\Exception $e) {
                $errors[] = "Fichier '{$file->getClientOriginalName()}': {$e->getMessage()}";
            }
        }

        return $this->json([
            'success' => empty($errors),
            'uploaded' => count($uploadedMedias),
            'failed' => count($errors),
            'medias' => $uploadedMedias,
            'errors' => $errors,
        ], empty($errors) ? 201 : 207, [], ['groups' => ['media:read']]);
    }

    /**
     * Upload depuis une URL (pour migration)
     * POST /api/media/upload-from-url
     * Body: {"url": "https://...", "folder": "khamareo/products", "tags": ["product"], "altText": "..."}
     */
    #[Route('/upload-from-url', name: 'media_upload_url', methods: ['POST'])]
    public function uploadFromUrl(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        $url = $data['url'] ?? null;
        if (!$url) {
            throw new BadRequestHttpException('URL manquante');
        }

        $folder = $data['folder'] ?? 'khamareo';
        $tags = $data['tags'] ?? [];
        $altText = $data['altText'] ?? null;

        try {
            $media = $this->mediaService->uploadFromUrl($url, $folder, $tags, $altText);

            return $this->json([
                'success' => true,
                'media' => $media,
            ], 201, [], ['groups' => ['media:read']]);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Mettre à jour les métadonnées d'un média
     * PUT /api/media/{id}
     * Body: {"altText": "...", "tags": ["tag1", "tag2"]}
     */
    #[Route('/{id}', name: 'media_update', methods: ['PUT', 'PATCH'])]
    public function update(Media $media, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $altText = $data['altText'] ?? null;
        $tags = $data['tags'] ?? null;

        try {
            $this->mediaService->updateMedia($media, $altText, $tags);

            return $this->json([
                'success' => true,
                'media' => $media,
            ], 200, [], ['groups' => ['media:read']]);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Supprimer un média
     * DELETE /api/media/{id}?cloudinary=true
     */
    #[Route('/{id}', name: 'media_delete', methods: ['DELETE'])]
    public function delete(Media $media, Request $request): JsonResponse
    {
        $deleteFromCloudinary = $request->query->getBoolean('cloudinary', true);

        try {
            $this->mediaService->deleteMedia($media, $deleteFromCloudinary);

            return $this->json([
                'success' => true,
                'message' => 'Média supprimé avec succès',
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Synchroniser avec Cloudinary
     * POST /api/media/{id}/sync
     */
    #[Route('/{id}/sync', name: 'media_sync', methods: ['POST'])]
    public function sync(Media $media): JsonResponse
    {
        try {
            $this->mediaService->syncWithCloudinary($media);

            return $this->json([
                'success' => true,
                'media' => $media,
            ], 200, [], ['groups' => ['media:read']]);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }


    /**
     * Upload batch (multiple URLs pour migration)
     * POST /api/media/batch-upload
     * Body: {"items": [{"url": "...", "altText": "...", "tags": [...]}, ...]}
     */
    #[Route('/batch-upload', name: 'media_batch_upload', methods: ['POST'])]
    public function batchUpload(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $items = $data['items'] ?? [];

        if (empty($items)) {
            throw new BadRequestHttpException('Aucun item à uploader');
        }

        $uploaded = [];
        $errors = [];

        foreach ($items as $index => $item) {
            $url = $item['url'] ?? null;
            if (!$url) {
                $errors[] = "Item #{$index}: URL manquante";
                continue;
            }

            try {
                $media = $this->mediaService->uploadFromUrl(
                    $url,
                    $item['folder'] ?? 'khamareo',
                    $item['tags'] ?? [],
                    $item['altText'] ?? null
                );
                
                $uploaded[] = $media;

            } catch (\Exception $e) {
                $errors[] = "Item #{$index} ({$url}): {$e->getMessage()}";
            }
        }

        return $this->json([
            'success' => empty($errors),
            'uploaded' => count($uploaded),
            'failed' => count($errors),
            'medias' => $uploaded,
            'errors' => $errors,
        ], empty($errors) ? 201 : 207, [], ['groups' => ['media:read']]);
    }
}