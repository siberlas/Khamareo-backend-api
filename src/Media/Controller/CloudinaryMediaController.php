<?php

namespace App\Media\Controller;

use App\Media\Service\CloudinaryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
#[Route('/api/cloudinary')]
#[IsGranted('ROLE_ADMIN')]
class CloudinaryMediaController extends AbstractController
{
    public function __construct(
        private CloudinaryService $cloudinaryService
    ) {}

    /**
     * Liste toutes les images du blog
     * 
     * GET /api/cloudinary/media?folder=khamareo/blog&limit=100&tags=featured
     */
    #[Route('/media', name: 'cloudinary_list_media', methods: ['GET'])]
    public function listMedia(Request $request): JsonResponse
    {
        $folder = $request->query->get('folder', 'khamareo/blog');
        $limit = (int) $request->query->get('limit', 100);
        $tags = $request->query->get('tags') 
            ? explode(',', $request->query->get('tags')) 
            : [];

        $result = $this->cloudinaryService->listImages($folder, $limit, $tags);

        if (!$result['success']) {
            return $this->json([
                'error' => $result['error'],
                'message' => 'Impossible de récupérer les images'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'images' => $result['images'],
            'total' => $result['total'],
            'folder' => $folder,
        ]);
    }

    /**
     * Récupère les détails d'une image par asset_id
     * 
     * GET /api/cloudinary/media/{assetId}
     */
    #[Route('/media/{assetId}', name: 'cloudinary_get_media', methods: ['GET'])]
    public function getMedia(string $assetId): JsonResponse
    {
        $result = $this->cloudinaryService->getImageByAssetId($assetId);

        if (!$result['success']) {
            return $this->json([
                'error' => $result['error'],
                'message' => 'Image non trouvée'
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->json($result['image']);
    }

    /**
     * Supprime une image par asset_id
     * 
     * DELETE /api/cloudinary/media/{assetId}
     */
    #[Route('/media/{assetId}', name: 'cloudinary_delete_media', methods: ['DELETE'])]
    public function deleteMedia(string $assetId): JsonResponse
    {
        $result = $this->cloudinaryService->deleteImage($assetId);

        if (!$result['success']) {
            return $this->json([
                'error' => $result['error'],
                'message' => $result['message']
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'message' => $result['message'],
            'assetId' => $assetId,
        ]);
    }

    /**
     * Met à jour les tags d'une image
     * 
     * PATCH /api/cloudinary/media/{assetId}/tags
     * Body: { "tags": ["blog", "featured"] }
     */
    #[Route('/media/{assetId}/tags', name: 'cloudinary_update_tags', methods: ['PATCH'])]
    public function updateTags(string $assetId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['tags']) || !is_array($data['tags'])) {
            return $this->json([
                'error' => 'Le champ "tags" est requis et doit être un tableau'
            ], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->cloudinaryService->updateImage($assetId, [
            'tags' => $data['tags']
        ]);

        if (!$result['success']) {
            return $this->json([
                'error' => $result['error'],
                'message' => $result['message']
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'message' => 'Tags mis à jour avec succès',
            'tags' => $result['tags'],
        ]);
    }

    /**
     * Ajoute des tags à une image
     * 
     * POST /api/cloudinary/media/{assetId}/tags
     * Body: { "tags": ["new-tag"] }
     */
    #[Route('/media/{assetId}/tags', name: 'cloudinary_add_tags', methods: ['POST'])]
    public function addTags(string $assetId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['tags']) || !is_array($data['tags'])) {
            return $this->json([
                'error' => 'Le champ "tags" est requis et doit être un tableau'
            ], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->cloudinaryService->addTags($assetId, $data['tags']);

        if (!$result['success']) {
            return $this->json([
                'error' => $result['error'],
                'message' => 'Impossible d\'ajouter les tags'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'message' => 'Tags ajoutés avec succès',
            'tags' => $result['tags'],
        ]);
    }

    /**
     * Obtient les statistiques du dossier
     * 
     * GET /api/cloudinary/stats?folder=khamareo/blog
     */
    #[Route('/stats', name: 'cloudinary_stats', methods: ['GET'])]
    public function getStats(Request $request): JsonResponse
    {
        $folder = $request->query->get('folder', 'khamareo/blog');

        $result = $this->cloudinaryService->getFolderStats($folder);

        if (!$result['success']) {
            return $this->json([
                'error' => $result['error'],
                'message' => 'Impossible de récupérer les statistiques'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json($result['stats']);
    }

    /**
     * Recherche d'images avec expression personnalisée
     * 
     * GET /api/cloudinary/search?expression=tags=featured AND format=jpg
     */
    #[Route('/search', name: 'cloudinary_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $expression = $request->query->get('expression');
        $limit = (int) $request->query->get('limit', 100);

        if (!$expression) {
            return $this->json([
                'error' => 'Le paramètre "expression" est requis'
            ], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->cloudinaryService->search($expression, $limit);

        if (!$result['success']) {
            return $this->json([
                'error' => $result['error'],
                'message' => 'Erreur lors de la recherche'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'images' => $result['images'],
            'total' => $result['total'],
            'expression' => $expression,
        ]);
    }

    /**
     * Upload une image (alternative au widget frontend)
     * 
     * POST /api/cloudinary/upload
     * Body: multipart/form-data with "file" field
     */
    #[Route('/upload', name: 'cloudinary_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $file = $request->files->get('file');

        if (!$file) {
            return $this->json([
                'error' => 'Aucun fichier uploadé'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Validation
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($file->getMimeType(), $allowedMimes)) {
            return $this->json([
                'error' => 'Format non autorisé. Formats acceptés : JPG, PNG, WebP, GIF'
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($file->getSize() > 10485760) { // 10 MB
            return $this->json([
                'error' => 'Fichier trop volumineux. Taille maximum : 10 MB'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Upload vers Cloudinary
        $folder = $request->request->get('folder', 'khamareo/blog');
        $tags = $request->request->get('tags') 
            ? explode(',', $request->request->get('tags')) 
            : ['blog'];

        $result = $this->cloudinaryService->uploadImage($file->getPathname(), [
            'asset_folder' => $folder,
            'tags' => $tags,
        ]);

        if (!$result['success']) {
            return $this->json([
                'error' => $result['error'],
                'message' => 'Erreur lors de l\'upload'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'message' => 'Image uploadée avec succès',
            'image' => [
                'assetId' => $result['assetId'],
                'publicId' => $result['publicId'],
                'url' => $result['url'],
                'width' => $result['width'],
                'height' => $result['height'],
                'format' => $result['format'],
                'bytes' => $result['bytes'],
                'tags' => $result['tags'],
            ]
        ], Response::HTTP_CREATED);
    }
}