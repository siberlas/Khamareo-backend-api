<?php

namespace App\Admin\Controller\Category;

use App\Catalog\Entity\Category;
use App\Catalog\Repository\CategoryRepository;
use App\Media\Entity\CategoryMedia;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
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

#[AsController]
#[Route('/api/admin/categories', name: 'admin_categories_creation_')]
class CategoryCreationController extends AbstractController
{
    private const MAX_IMAGE_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB
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
     * Créer une catégorie avec images (main + banner optionnelle)
     * 
     * POST /api/admin/categories/create
     * 
     * FormData:
     *   name: string (requis)
     *   slug: string (requis)
     *   description: string (optionnel)
     *   parentId: uuid (optionnel - si sous-catégorie)
     *   displayOrder: int (optionnel, défaut 0)
     *   isEnabled: boolean (optionnel, défaut true)
     *   mainImage: File (optionnel)
     *   bannerImage: File (optionnel)
     * 
     * Response:
     * {
     *   "success": true,
     *   "category": {...},
     *   "message": "Catégorie créée avec succès"
     * }
     */
    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            // Validation champs requis
            $name = $request->request->get('name');
            $slug = $request->request->get('slug');

            if (!$name || !$slug) {
                return $this->json([
                    'success' => false,
                    'error' => 'Les champs name et slug sont requis'
                ], 400);
            }

            // Vérifier slug unique
            $existingCategory = $this->categoryRepository->findOneBy(['slug' => $slug]);
            if ($existingCategory) {
                return $this->json([
                    'success' => false,
                    'error' => 'Une catégorie avec ce slug existe déjà'
                ], 400);
            }

            // Créer la catégorie
            $category = new Category();
            $category->setName($name);
            $category->setSlug($slug);

            // Description
            if ($description = $request->request->get('description')) {
                $category->setDescription($description);
            }

            // ✅ FIX : Gestion améliorée du parent avec messages d'erreur clairs
            if ($parentId = $request->request->get('parentId')) {
                // Étape 1 : Valider le format UUID
                try {
                    $parentUuid = Uuid::fromString($parentId);
                } catch (\InvalidArgumentException $e) {
                    $this->logger->warning('Invalid parent UUID format', [
                        'parentId' => $parentId,
                        'error' => $e->getMessage(),
                    ]);
                    return $this->json([
                        'success' => false,
                        'error' => 'Format UUID parent invalide'
                    ], 400);
                }

                // Étape 2 : Vérifier que la catégorie parent existe
                $parent = $this->categoryRepository->find($parentUuid);
                
                if (!$parent) {
                    $this->logger->warning('Parent category not found', [
                        'parentId' => $parentId,
                    ]);
                    return $this->json([
                        'success' => false,
                        'error' => 'Catégorie parente introuvable'
                    ], 404);
                }

                // Étape 3 : Vérifier la politique (profondeur max, etc.)
                try {
                    $this->categoryPolicy->assertCanAttachToParent($parent);
                } catch (\Exception $e) {
                    $this->logger->warning('Cannot attach to parent', [
                        'parentId' => $parentId,
                        'parentName' => $parent->getName(),
                        'error' => $e->getMessage(),
                    ]);
                    return $this->json([
                        'success' => false,
                        'error' => $e->getMessage()  // ← Afficher le VRAI message
                    ], 400);
                }

                // Étape 4 : Attacher le parent
                $category->setParent($parent);
                
                // Étape 5 : Vérifier cohérence enabled/disabled
                if ($category->isEnabled() && !$parent->isEnabled()) {
                    $this->logger->warning('Cannot enable category with disabled parent', [
                        'parentId' => $parentId,
                        'parentName' => $parent->getName(),
                    ]);
                    return $this->json([
                        'success' => false, 
                        'error' => 'Activation impossible : le parent est désactivé'
                    ], 400);
                }
            }

            // Display order
            if ($displayOrder = $request->request->get('displayOrder')) {
                $category->setDisplayOrder((int) $displayOrder);
            }

            // isEnabled
            $isEnabled = true;
            if ($request->request->has('isEnabled')) {
                $isEnabled = filter_var($request->request->get('isEnabled'), FILTER_VALIDATE_BOOLEAN);
            }

            try {
                $this->categoryStatusManager->applyIsEnabled($category, $isEnabled);
            } catch (\App\Admin\Exception\CategoryStatusException $e) {
                return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
            }

            // Persister la catégorie
            $this->em->persist($category);

            // Gérer image principale
            /** @var UploadedFile|null $mainImageFile */
            $mainImageFile = $request->files->get('mainImage');
            if ($mainImageFile) {
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
            /** @var UploadedFile|null $bannerImageFile */
            $bannerImageFile = $request->files->get('bannerImage');
            if ($bannerImageFile) {
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

            $this->logger->info('Category created', [
                'category_id' => $category->getId()->toRfc4122(),
                'slug' => $category->getSlug(),
                'parent_id' => $category->getParent()?->getId()->toRfc4122(),
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
                    'mainImage' => $category->getMainMedia()?->getUrl(),
                    'bannerImage' => $category->getBannerMedia()?->getUrl(),
                ],
                'message' => 'Catégorie créée avec succès'
            ], 201);

        } catch (\Exception $e) {
            $this->logger->error('Category creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la création : ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload une image sur Cloudinary et créer l'entité Media
     */
    private function uploadAndCreateMedia(UploadedFile $file, string $usage): ?Media
    {
        // Validation taille
        if ($file->getSize() > self::MAX_IMAGE_SIZE_BYTES) {
            $this->logger->warning('Image too large', [
                'size' => $file->getSize(),
                'max' => self::MAX_IMAGE_SIZE_BYTES,
            ]);
            return null;
        }

        // Validation type MIME
        if (!in_array($file->getMimeType(), self::ALLOWED_IMAGE_TYPES)) {
            $this->logger->warning('Invalid image type', [
                'mime_type' => $file->getMimeType(),
            ]);
            return null;
        }

        try {
            // Upload sur Cloudinary
            $uploadResult = $this->cloudinaryService->uploadImage(
                $file->getPathname(),
                self::CLOUDINARY_FOLDER,
                [
                    'resource_type' => 'image',
                    'folder' => self::CLOUDINARY_FOLDER,
                ]
            );

            if (empty($uploadResult['success']) || empty($uploadResult['public_id'])) {
                $this->logger->error('Cloudinary upload failed', [
                    'result' => $uploadResult,
                ]);
                return null;
            }

            // Créer Media
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
                'usage' => $usage,
            ]);
            return null;
        }
    }
}