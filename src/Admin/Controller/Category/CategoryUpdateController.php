<?php

namespace App\Admin\Controller\Category;

use App\Catalog\Repository\CategoryRepository;
use App\Media\Entity\CategoryMedia;
use App\Media\Entity\Media;
use App\Media\Service\CloudinaryService;
use App\Admin\Service\CategoryStatusManager;
use App\Admin\Service\CategoryPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

#[AsController]
#[Route('/api/admin/categories', name: 'admin_categories_update_')]
class CategoryUpdateController extends AbstractController
{
    private const MAX_IMAGE_SIZE_BYTES = 10 * 1024 * 1024;
    private const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const CLOUDINARY_FOLDER = 'khamareo/categories';

    public function __construct(
        private EntityManagerInterface $em,
        private CategoryRepository $categoryRepository,
        private CloudinaryService $cloudinaryService,
        private LoggerInterface $logger,
        private CategoryStatusManager $categoryStatusManager,
        private CategoryPolicy $categoryPolicy,
        private TagAwareCacheInterface $catalogCache,
    ) {}

    /**
     * Modifier une catégorie (JSON simple - sans images)
     * 
     * PATCH /api/admin/categories/{id}
     * 
     * Body (JSON):
     * {
     *   "name": "Nouveau nom",
     *   "slug": "nouveau-slug",
     *   "description": "...",
     *   "parentId": "uuid" | null,
     *   "displayOrder": 5,
     *   "isEnabled": true
     * }
     * 
     * Response:
     * {
     *   "success": true,
     *   "category": {...},
     *   "message": "Catégorie mise à jour avec succès"
     * }
     */
    #[Route('/{id}', name: 'update', methods: ['PATCH'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function update(string $id, Request $request): JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
            $category = $this->categoryRepository->find($uuid);

            if (!$category) {
                return $this->json([
                    'success' => false,
                    'error' => 'Catégorie introuvable'
                ], 404);
            }

            $data = json_decode($request->getContent(), true);

            if (!$data) {
                return $this->json([
                    'success' => false,
                    'error' => 'Données JSON invalides'
                ], 400);
            }

            // Mettre à jour name
            if (isset($data['name'])) {
                $category->setName($data['name']);
            }

            // Mettre à jour slug
            if (isset($data['slug'])) {
                // Vérifier que le slug est unique (sauf pour la catégorie courante)
                $existing = $this->categoryRepository->findOneBy(['slug' => $data['slug']]);
                if ($existing && !$existing->getId()->equals($category->getId())) {
                    return $this->json([
                        'success' => false,
                        'error' => 'Ce slug est déjà utilisé par une autre catégorie'
                    ], 400);
                }
                $category->setSlug($data['slug']);
            }

            // Mettre à jour description
            if (isset($data['description'])) {
                $category->setDescription($data['description']);
            }

            // Mettre à jour parent
           if (array_key_exists('parentId', $data)) {
            if ($data['parentId'] === null) {
                $category->setParent(null);
            } else {
                $parentUuid = Uuid::fromString($data['parentId']);
                $parent = $this->categoryRepository->find($parentUuid);

                if (!$parent) {
                    return $this->json([
                        'success' => false,
                        'error' => 'Catégorie parente introuvable'
                    ], 404);
                }

                if ($this->wouldCreateCycle($category, $parent)) {
                    return $this->json([
                        'success' => false,
                        'error' => 'Cette assignation créerait une boucle dans l\'arbre'
                    ], 400);
                }
                $this->categoryPolicy->assertCanAttachToParent($parent);
                $category->setParent($parent);
            }

            // appliquer règle liée au parent (si parent disabled => category/descendants disabled)
            $this->categoryStatusManager->onParentChanged($category);
        }

        // Mettre à jour isEnabled
        if (array_key_exists('isEnabled', $data)) {
            try {
                $this->categoryStatusManager->applyIsEnabled($category, (bool) $data['isEnabled']);
            } catch (\App\Admin\Exception\CategoryStatusException $e) {
                return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
            }
        }

            // Mettre à jour displayOrder
            if (isset($data['displayOrder'])) {
                $category->setDisplayOrder((int) $data['displayOrder']);
            }

            // Mettre à jour isEnabled
           if (isset($data['isEnabled'])) {
                $new = (bool) $data['isEnabled'];
                $old = $category->isEnabled();

                if ($new === true && $old === false) {
                    // Activation : parent doit être activé
                    try {
                        $this->categoryPolicy->enable($category);
                    } catch (\DomainException $e) {
                        return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
                    }
                }

                if ($new === false && $old === true) {
                    // Désactivation : cascade
                    $this->categoryPolicy->disableWithDescendants($category);
                }
            }

            $this->em->flush();
            $this->catalogCache->invalidateTags([CategoryRepository::CACHE_TAG]);

            $this->logger->info('Category updated', [
                'category_id' => $category->getId()->toRfc4122(),
                'changes' => array_keys($data),
            ]);

            return $this->json([
                'success' => true,
                'category' => [
                    'id' => $category->getId()->toRfc4122(),
                    'name' => $category->getName(),
                    'slug' => $category->getSlug(),
                    'description' => $category->getDescription(),
                    'displayOrder' => $category->getDisplayOrder(),
                    'isEnabled' => $category->isEnabled(),
                    'parent' => $category->getParent() ? [
                        'id' => $category->getParent()->getId()->toRfc4122(),
                        'name' => $category->getParent()->getName(),
                    ] : null,
                ],
                'message' => 'Catégorie mise à jour avec succès'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Category update failed', [
                'category_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la mise à jour : ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Modifier une catégorie avec images (FormData)
     * 
     * PUT /api/admin/categories/{id}/update-with-images
     * 
     * FormData:
     *   name: string
     *   slug: string
     *   description: string
     *   parentId: uuid | null
     *   displayOrder: int
     *   isEnabled: boolean
     *   mainImage: File (optionnel - nouvelle image principale)
     *   replaceMainImage: boolean (si true, remplace l'ancienne)
     *   bannerImage: File (optionnel - nouvelle image bannière)
     *   replaceBannerImage: boolean (si true, remplace l'ancienne)
     * 
     * Response:
     * {
     *   "success": true,
     *   "category": {...},
     *   "message": "Catégorie mise à jour avec succès"
     * }
     */
    #[Route('/{id}/update-with-images', name: 'update_with_images', methods: ['PUT'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function updateWithImages(string $id, Request $request): JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
            $category = $this->categoryRepository->find($uuid);

            if (!$category) {
                return $this->json([
                    'success' => false,
                    'error' => 'Catégorie introuvable'
                ], 404);
            }

            // Mettre à jour les champs texte (comme PATCH)
            if ($name = $request->request->get('name')) {
                $category->setName($name);
            }
            if ($slug = $request->request->get('slug')) {
                $existing = $this->categoryRepository->findOneBy(['slug' => $slug]);
                if ($existing && !$existing->getId()->equals($category->getId())) {
                    return $this->json([
                        'success' => false,
                        'error' => 'Ce slug est déjà utilisé'
                    ], 400);
                }
                $category->setSlug($slug);
            }
            if ($request->request->has('description')) {
                $category->setDescription($request->request->get('description'));
            }
            if ($request->request->has('displayOrder')) {
                $category->setDisplayOrder((int) $request->request->get('displayOrder'));
            }
            if ($request->request->has('isEnabled')) {
                $isEnabled = filter_var($request->request->get('isEnabled'), FILTER_VALIDATE_BOOLEAN);
                try {
                    $this->categoryStatusManager->applyIsEnabled($category, $isEnabled);
                } catch (\App\Admin\Exception\CategoryStatusException $e) {
                    return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
                }
            }

            // Gérer image principale
            $replaceMainImage = filter_var($request->request->get('replaceMainImage'), FILTER_VALIDATE_BOOLEAN);
            /** @var UploadedFile|null $mainImageFile */
            $mainImageFile = $request->files->get('mainImage');

            if ($mainImageFile) {
                if ($replaceMainImage) {
                    // Supprimer l'ancienne image principale
                    $this->deleteMediaByUsage($category, 'main');
                }

                // Ajouter la nouvelle
                $mainMedia = $this->uploadAndCreateMedia($mainImageFile, 'main');
                if ($mainMedia) {
                    $categoryMedia = new CategoryMedia();
                    $categoryMedia->setCategory($category);
                    $categoryMedia->setMedia($mainMedia);
                    $categoryMedia->setMediaUsage('main');
                    $this->em->persist($categoryMedia);
                }
            }

            // Gérer image bannière
            $replaceBannerImage = filter_var($request->request->get('replaceBannerImage'), FILTER_VALIDATE_BOOLEAN);
            /** @var UploadedFile|null $bannerImageFile */
            $bannerImageFile = $request->files->get('bannerImage');

            if ($bannerImageFile) {
                if ($replaceBannerImage) {
                    // Supprimer l'ancienne bannière
                    $this->deleteMediaByUsage($category, 'banner');
                }

                // Ajouter la nouvelle
                $bannerMedia = $this->uploadAndCreateMedia($bannerImageFile, 'banner');
                if ($bannerMedia) {
                    $categoryMedia = new CategoryMedia();
                    $categoryMedia->setCategory($category);
                    $categoryMedia->setMedia($bannerMedia);
                    $categoryMedia->setMediaUsage('banner');
                    $this->em->persist($categoryMedia);
                }
            }

            $this->em->flush();
            $this->catalogCache->invalidateTags([CategoryRepository::CACHE_TAG]);

            $this->logger->info('Category updated with images', [
                'category_id' => $category->getId()->toRfc4122(),
            ]);

            return $this->json([
                'success' => true,
                'category' => [
                    'id' => $category->getId()->toRfc4122(),
                    'name' => $category->getName(),
                    'slug' => $category->getSlug(),
                    'mainImage' => $category->getMainMedia()?->getUrl(),
                    'bannerImage' => $category->getBannerMedia()?->getUrl(),
                ],
                'message' => 'Catégorie mise à jour avec succès'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Category update with images failed', [
                'category_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la mise à jour : ' . $e->getMessage()
            ], 500);
        }
    }

    private function uploadAndCreateMedia(UploadedFile $file, string $usage): ?Media
    {
        if ($file->getSize() > self::MAX_IMAGE_SIZE_BYTES) {
            return null;
        }

        if (!in_array($file->getMimeType(), self::ALLOWED_IMAGE_TYPES)) {
            return null;
        }

        try {
            $uploadResult = $this->cloudinaryService->uploadImage(
                $file->getPathname(),
                self::CLOUDINARY_FOLDER,
                [
                    'resource_type' => 'image',
                    'folder' => self::CLOUDINARY_FOLDER,
                ]
            );

            if (empty($uploadResult['success'])) {
                return null;
            }

            $media = new Media();
            $media->setCloudinaryPublicId($uploadResult['public_id']);
            $media->setUrl($uploadResult['secure_url']);
            $media->setThumbnailUrl($uploadResult['thumbnail_url'] ?? null);
            $media->setFilename($file->getClientOriginalName());
            $media->setMediaType('image');
            $media->setMimeType($file->getMimeType());
            $media->setWidth($uploadResult['width'] ?? null);
            $media->setHeight($uploadResult['height'] ?? null);
            $media->setFileSize($file->getSize());
            $media->setFolder(self::CLOUDINARY_FOLDER);
            $media->setAltText($usage . ' image');

            $this->em->persist($media);

            return $media;

        } catch (\Exception $e) {
            $this->logger->error('Image upload failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function deleteMediaByUsage($category, string $usage): void
    {
        foreach ($category->getCategoryMedias() as $categoryMedia) {
            if ($categoryMedia->getMediaUsage() === $usage) {
                $media = $categoryMedia->getMedia();
                $cloudinaryPublicId = $media->getCloudinaryPublicId();

                // Supprimer CategoryMedia et Media
                $this->em->remove($categoryMedia);
                $this->em->remove($media);

                // Supprimer de Cloudinary
                if ($cloudinaryPublicId) {
                    $this->cloudinaryService->deleteAsset($cloudinaryPublicId, 'image', true);
                }
            }
        }
    }

    private function wouldCreateCycle($category, $newParent): bool
    {
        $current = $newParent;
        
        while ($current !== null) {
            if ($current->getId()->equals($category->getId())) {
                return true;
            }
            $current = $current->getParent();
        }
        
        return false;
    }
}