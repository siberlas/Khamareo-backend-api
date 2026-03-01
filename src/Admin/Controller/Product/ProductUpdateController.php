<?php

namespace App\Admin\Controller\Product;

use App\Catalog\Entity\Product;
use App\Catalog\Repository\BadgeRepository;
use App\Catalog\Repository\CategoryRepository;
use App\Catalog\Repository\ProductRepository;
use App\Media\Service\CloudinaryService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Uid\Uuid;
use App\Media\Entity\Media;
use App\Media\Entity\ProductMedia;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[AsController]
#[Route('/api/admin/products', name: 'admin_products_update_')]
class ProductUpdateController extends AbstractController
{
    private const MAX_IMAGE_SIZE_MB = 10;
    private const MAX_IMAGE_SIZE_BYTES = self::MAX_IMAGE_SIZE_MB * 1024 * 1024;

    public function __construct(
        private EntityManagerInterface $em,
        private ProductRepository $productRepository,
        private CategoryRepository $categoryRepository,
        private BadgeRepository $badgeRepository,
        private CloudinaryService $cloudinaryService,
        private SluggerInterface $slugger,
        private LoggerInterface $logger
    ) {}

    /**
     * Modifier un produit existant
     * 
     * PATCH /api/admin/products/{id}
     * 
     * Body (JSON):
     * {
     *   "name": "Nouveau nom",
     *   "slug": "nouveau-slug",
     *   "categoryId": "uuid",
     *   "description": "...",
     *   "price": 50.00,
     *   "originalPrice": 60.00,
     *   "stock": 20,
     *   "weightGrams": 250,
     *   "badge": "Bio",
     *   "benefits": ["Antioxydant", "Énergisant"],
     *   "ingredients": "...",
     *   "usage": "...",
    *   "isEnabled": true,
    *   "isFeatured": false
     * }
     * 
     * Response:
     * {
     *   "success": true,
     *   "product": {...},
     *   "message": "Produit mis à jour avec succès"
     * }
     */
    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        try {
            // Récupérer le produit
            $uuid = Uuid::fromString($id);
            $product = $this->productRepository->find($uuid);

            if (!$product) {
                return $this->json([
                    'success' => false,
                    'error' => 'Produit introuvable'
                ], 404);
            }

            // Décoder les données
            $data = json_decode($request->getContent(), true);

            if (!$data) {
                return $this->json([
                    'success' => false,
                    'error' => 'Données JSON invalides'
                ], 400);
            }

            // Mettre à jour les champs fournis
            if (isset($data['name'])) {
                $product->setName($data['name']);
            }

            if (isset($data['slug'])) {
                // Vérifier unicité du slug
                $existingProduct = $this->productRepository->findOneBy(['slug' => $data['slug']]);
                if ($existingProduct && $existingProduct->getId() !== $product->getId()) {
                    return $this->json([
                        'success' => false,
                        'error' => 'Ce slug est déjà utilisé par un autre produit'
                    ], 400);
                }
                $product->setSlug($data['slug']);
            }

            if (isset($data['categoryId'])) {
                $category = $this->categoryRepository->find($data['categoryId']);
                if (!$category) {
                    return $this->json([
                        'success' => false,
                        'error' => 'Catégorie introuvable'
                    ], 404);
                }
                $product->setCategory($category);
            }

            if (isset($data['description'])) {
                $product->setDescription($data['description']);
            }

            if (isset($data['price'])) {
                $product->setPrice((float) $data['price']);
            }

            if (array_key_exists('originalPrice', $data)) {
                $newOriginalPrice = $data['originalPrice'] ? (float) $data['originalPrice'] : null;
                $product->setOriginalPrice($newOriginalPrice);
                // Propagate to ProductPrice records (multi-currency pricing)
                foreach ($product->getPrices() as $productPrice) {
                    $productPrice->setOriginalPrice($newOriginalPrice !== null ? (string) $newOriginalPrice : null);
                }
            }

            if (isset($data['stock'])) {
                $product->setStock((int) $data['stock']);
            }

            if (array_key_exists('weightGrams', $data)) {
                $product->setWeightGrams($data['weightGrams'] ? (int) $data['weightGrams'] : null);
            }

            if (array_key_exists('badge', $data)) {
                if ($data['badge'] === null) {
                    $product->setBadge(null);
                } else {
                    // Accept IRI ("/api/badges/uuid") or raw UUID string
                    $badgeValue = $data['badge'];
                    if (is_string($badgeValue) && str_contains($badgeValue, '/')) {
                        $badgeValue = basename($badgeValue);
                    }
                    $badge = $this->badgeRepository->find($badgeValue);
                    $product->setBadge($badge ?: null);
                }
            }

            if (isset($data['benefits'])) {
                $product->setBenefits(is_array($data['benefits']) ? $data['benefits'] : []);
            }

            if (isset($data['ingredients'])) {
                $product->setIngredients($data['ingredients']);
            }

            if (isset($data['usage'])) {
                $product->setUsage($data['usage']);
            }

            if (isset($data['isEnabled'])) {
                $product->setIsEnabled((bool) $data['isEnabled']);
            }

            if (isset($data['isFeatured'])) {
                $wantFeatured = (bool) $data['isFeatured'];
                if ($wantFeatured && !$product->getIsFeatured()) {
                    $featuredCount = $this->productRepository->count([
                        'isFeatured' => true,
                        'isDeleted' => false,
                    ]);
                    if ($featuredCount >= 4) {
                        return $this->json([
                            'success' => false,
                            'error' => 'Limite atteinte : 4 produits vedettes maximum'
                        ], 400);
                    }
                }
                $product->setIsFeatured($wantFeatured);
            }

            // GÉRER LES PRODUITS LIÉS
            if (isset($data['relatedProductIds'])) {
                // Supprimer tous les produits liés actuels
                foreach ($product->getRelatedProducts() as $related) {
                    $product->removeRelatedProduct($related);
                }
                
                // Ajouter les nouveaux
                if (is_array($data['relatedProductIds'])) {
                    foreach ($data['relatedProductIds'] as $relatedId) {
                        try {
                            $relatedUuid = \Symfony\Component\Uid\Uuid::fromString($relatedId);
                            $relatedProduct = $this->productRepository->find($relatedUuid);
                            
                            if ($relatedProduct && $relatedProduct->getId() !== $product->getId()) {
                                $product->addRelatedProduct($relatedProduct);
                            }
                        } catch (\Exception $e) {
                            // Skip invalid UUID
                            continue;
                        }
                    }
                }
            }

            // Sauvegarder
            $this->em->flush();

            $this->logger->info('Product updated', [
                'product_id' => $product->getId()->toRfc4122(),
                'updated_fields' => array_keys($data),
            ]);

            return $this->json([
                'success' => true,
                'product' => [
                    'id' => $product->getId()->toRfc4122(),
                    'slug' => $product->getSlug(),
                    'name' => $product->getName(),
                    'price' => $product->getPrice(),
                    'stock' => $product->getStock(),
                    'isEnabled' => $product->getIsEnabled(),
                    'isFeatured' => $product->getIsFeatured(),
                ],
                'message' => 'Produit mis à jour avec succès'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Product update failed', [
                'product_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la mise à jour : ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/{id}/update-with-images', name: 'update_with_images', methods: ['PUT', 'POST'])]
    public function updateWithImages(string $id, Request $request): JsonResponse
    {
        try {
            // ✅ Debug ultra utile (pour vérifier les clés exactes côté files)
            $allFiles = $request->files->all();

            $this->logger->info('Update product with images (request debug)', [
                'content_type' => $request->headers->get('content-type'),
                'content_length' => $request->headers->get('content-length'),
                'request_keys' => array_keys($request->request->all()),
                'files_top_keys' => array_keys($allFiles),
                'files_dump' => array_map(
                    fn($v) => is_array($v) ? 'array('.count($v).')' : (is_object($v) ? get_class($v) : gettype($v)),
                    $allFiles
                ),
                'has_mainImage' => $request->files->has('mainImage'),
            ]);

            $uuid = Uuid::fromString($id);

            /** @var Product|null $product */
            $product = $this->productRepository->find($uuid);
            if (!$product) {
                return $this->json(['success' => false, 'error' => 'Produit introuvable'], 404);
            }

            // ----------------------------
            // 1) Update champs (texte)
            // ----------------------------
            if ($request->request->has('name')) {
                $product->setName((string) $request->request->get('name'));
            }

            if ($request->request->has('slug')) {
                $slug = (string) $request->request->get('slug');

                $existing = $this->productRepository->findOneBy(['slug' => $slug]);
                if ($existing && $existing->getId() !== $product->getId()) {
                    return $this->json(['success' => false, 'error' => 'Ce slug est déjà utilisé'], 400);
                }
                $product->setSlug($slug);
            }

            // ✅ Admin: categoryId = UUID (string)
            if ($request->request->has('categoryId')) {
                $categoryId = (string) $request->request->get('categoryId');
                $category = $this->categoryRepository->find($categoryId);

                if (!$category) {
                    return $this->json(['success' => false, 'error' => 'Catégorie introuvable'], 404);
                }
                $product->setCategory($category);
            }

            if ($request->request->has('description')) {
                $product->setDescription($request->request->get('description'));
            }
            if ($request->request->has('price')) {
                $product->setPrice((float) $request->request->get('price'));
            }
            if ($request->request->has('originalPrice')) {
                $originalPrice = $request->request->get('originalPrice');
                $newOriginalPrice = ($originalPrice !== null && $originalPrice !== '') ? (float) $originalPrice : null;
                $product->setOriginalPrice($newOriginalPrice);
                // Propagate to ProductPrice records (multi-currency pricing)
                foreach ($product->getPrices() as $productPrice) {
                    $productPrice->setOriginalPrice($newOriginalPrice !== null ? (string) $newOriginalPrice : null);
                }
            }
            if ($request->request->has('stock')) {
                $product->setStock((int) $request->request->get('stock'));
            }
            if ($request->request->has('weightGrams')) {
                $weightGrams = $request->request->get('weightGrams');
                $product->setWeightGrams($weightGrams !== null && $weightGrams !== '' ? (int) $weightGrams : null);
            }

            if ($request->request->has('ingredients')) {
                $product->setIngredients($request->request->get('ingredients'));
            }
            if ($request->request->has('usage')) {
                $product->setUsage($request->request->get('usage'));
            }
            if ($request->request->has('isEnabled')) {
                $product->setIsEnabled(filter_var($request->request->get('isEnabled'), FILTER_VALIDATE_BOOLEAN));
            }

            if ($request->request->has('isFeatured')) {
                $wantFeatured = filter_var($request->request->get('isFeatured'), FILTER_VALIDATE_BOOLEAN);
                if ($wantFeatured && !$product->getIsFeatured()) {
                    $featuredCount = $this->productRepository->count([
                        'isFeatured' => true,
                        'isDeleted' => false,
                    ]);
                    if ($featuredCount >= 4) {
                        return $this->json([
                            'success' => false,
                            'error' => 'Limite atteinte : 4 produits vedettes maximum'
                        ], 400);
                    }
                }
                $product->setIsFeatured($wantFeatured);
            }

            // ----------------------------
            // 2) Main image (optionnelle)
            // ----------------------------
            /** @var UploadedFile|null $mainImage */
            $mainImage = $request->files->get('mainImage');
            $replaceMainImage = $request->request->getBoolean('replaceMainImage', false);

            $oldPrimaryCloudinaryPublicId = null;
            $oldPrimaryProductMedia = null;
            $oldPrimaryMedia = null;

            if ($mainImage && $replaceMainImage) {
                foreach ($product->getProductMedias()->toArray() as $pm) {
                    if ($pm->isPrimary()) {
                        $oldPrimaryProductMedia = $pm;
                        $oldPrimaryMedia = $pm->getMedia();
                        $oldPrimaryCloudinaryPublicId = $oldPrimaryMedia?->getCloudinaryPublicId();
                        break;
                    }
                }
            }

            if ($mainImage instanceof UploadedFile) {
                $this->logger->info('Uploading new main image for product', [
                    'product_id' => $product->getId()->toRfc4122(),
                    'original_filename' => $mainImage->getClientOriginalName(),
                    'replaceMainImage' => $replaceMainImage,
                ]);

                $upload = $this->uploadAndCreateMedia($mainImage);
                if (!($upload['success'] ?? false)) {
                    return $this->json([
                        'success' => false,
                        'error' => 'Erreur upload image principale : ' . ($upload['error'] ?? 'unknown'),
                    ], 500);
                }

                /** @var Media $newMedia */
                $newMedia = $upload['media'];

                if ($replaceMainImage && $oldPrimaryProductMedia) {
                    $this->em->remove($oldPrimaryProductMedia);
                    if ($oldPrimaryMedia) {
                        $this->em->remove($oldPrimaryMedia);
                    }
                } else {
                    foreach ($product->getProductMedias()->toArray() as $pm) {
                        if ($pm->isPrimary()) {
                            $pm->setIsPrimary(false);
                        }
                    }
                }

                $productMedia = new ProductMedia();
                $productMedia->setProduct($product);
                $productMedia->setMedia($newMedia);
                $productMedia->setIsPrimary(true);
                $productMedia->setDisplayOrder(0);

                $product->addProductMedia($productMedia);
                $product->setImageUrl($newMedia->getUrl());

                $this->em->persist($newMedia);
                $this->em->persist($productMedia);
            }

            // ----------------------------
            // 3) Gallery images (multi)
            // ----------------------------
            // ✅ Lecture robuste: on inspecte la collection entière
            $galleryImages = [];

            // cas 1: le front envoie galleryImages[] (très courant)
            if (isset($allFiles['galleryImages']) && is_array($allFiles['galleryImages'])) {
                $galleryImages = $allFiles['galleryImages'];
            }
            // cas 2: certains clients envoient la clé "galleryImages[]"
            elseif (isset($allFiles['galleryImages[]']) && is_array($allFiles['galleryImages[]'])) {
                $galleryImages = $allFiles['galleryImages[]'];
            }
            // cas 3: parfois Symfony te renvoie un seul UploadedFile
            else {
                $single = $request->files->get('galleryImages');
                if ($single instanceof UploadedFile) {
                    $galleryImages = [$single];
                }
            }

            // ✅ Nettoyage/normalisation (ne garder que UploadedFile)
            $galleryImages = array_values(array_filter(
                is_array($galleryImages) ? $galleryImages : [],
                fn($f) => $f instanceof UploadedFile
            ));

            $this->logger->info('Update product with images (gallery parsed)', [
                'product_id' => $product->getId()->toRfc4122(),
                'gallery_count' => count($galleryImages),
                'gallery_names' => array_map(fn(UploadedFile $f) => $f->getClientOriginalName(), $galleryImages),
            ]);

            if (count($galleryImages) > 0) {
                // ✅ displayOrder: on part après le max actuel
                $maxOrder = 0;
                foreach ($product->getProductMedias()->toArray() as $pm) {
                    $maxOrder = max($maxOrder, (int) $pm->getDisplayOrder());
                }
                $displayOrder = $maxOrder + 1;

                foreach ($galleryImages as $file) {
                    $upload = $this->uploadAndCreateMedia($file);

                    if (!($upload['success'] ?? false)) {
                        // Choix: on skip mais on log
                        $this->logger->error('Gallery upload failed', [
                            'product_id' => $product->getId()->toRfc4122(),
                            'file' => $file->getClientOriginalName(),
                            'error' => $upload['error'] ?? 'unknown',
                        ]);
                        continue;
                    }

                    /** @var Media $media */
                    $media = $upload['media'];

                    $pm = new ProductMedia();
                    $pm->setProduct($product);
                    $pm->setMedia($media);
                    $pm->setIsPrimary(false);
                    $pm->setDisplayOrder($displayOrder++);

                    $product->addProductMedia($pm);

                    $this->em->persist($media);
                    $this->em->persist($pm);
                }
            }

            // GÉRER LES PRODUITS LIÉS
            if ($request->request->has('relatedProductIds')) {
                // Supprimer tous les produits liés actuels
                foreach ($product->getRelatedProducts() as $related) {
                    $product->removeRelatedProduct($related);
                }
                
                // Ajouter les nouveaux
                $relatedProductIds = $request->request->all('relatedProductIds');
                if (is_array($relatedProductIds)) {
                    foreach ($relatedProductIds as $relatedId) {
                        try {
                            $relatedUuid = \Symfony\Component\Uid\Uuid::fromString($relatedId);
                            $relatedProduct = $this->productRepository->find($relatedUuid);
                            
                            if ($relatedProduct && $relatedProduct->getId() !== $product->getId()) {
                                $product->addRelatedProduct($relatedProduct);
                            }
                        } catch (\Exception $e) {
                            // Skip invalid UUID
                            continue;
                        }
                    }
                }
            }

            // ----------------------------
            // 4) Flush DB
            // ----------------------------
            $this->em->flush();

            // ----------------------------
            // 5) Suppression Cloudinary ancienne primary (après flush)
            // ----------------------------
            if ($mainImage instanceof UploadedFile && $replaceMainImage && $oldPrimaryCloudinaryPublicId) {
                $deleteResult = $this->cloudinaryService->deleteAsset(
                    $oldPrimaryCloudinaryPublicId,
                    'image',
                    true // invalidate CDN
                );

                if (empty($deleteResult['success'])) {
                    $this->logger->error('Cloudinary old image deletion failed', [
                        'publicId' => $oldPrimaryCloudinaryPublicId,
                        'error' => $deleteResult['error'] ?? 'unknown',
                    ]);
                }
            }

            return $this->json([
                'success' => true,
                'product' => [
                    'id' => $product->getId()->toRfc4122(),
                    'slug' => $product->getSlug(),
                    'name' => $product->getName(),
                    'price' => $product->getPrice(),
                    'stock' => $product->getStock(),
                    'isEnabled' => $product->getIsEnabled(),
                    'primaryImage' => $product->getPrimaryMedia()?->getUrl(),
                    'imagesCount' => $product->getProductMedias()->count(),
                ],
                'message' => 'Produit mis à jour avec succès',
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('Product update with images failed', [
                'product_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la mise à jour : ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Helper stable
     */
    private function uploadAndCreateMedia(UploadedFile $file): array
    {
        try {
            $allowedMimeTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
            $mimeType = (string) $file->getMimeType();

            if (!in_array($mimeType, $allowedMimeTypes, true)) {
                return ['success' => false, 'error' => 'Format non autorisé : ' . $mimeType];
            }

            $size = (int) $file->getSize();
            if ($size > self::MAX_IMAGE_SIZE_BYTES) {
                return ['success' => false, 'error' => 'Fichier trop volumineux'];
            }

            // ✅ IMPORTANT: pour Cloudinary, utiliser "folder" (pas "asset_folder")
            $uploadOptions = [
                'folder' => 'khamareo/products',
                'tags' => ['product'],
                'resource_type' => 'image',
            ];

            $uploadResult = $this->cloudinaryService->uploadImage($file->getPathname(), $uploadOptions);

            if (empty($uploadResult['success'])) {
                return ['success' => false, 'error' => $uploadResult['error'] ?? 'Erreur Cloudinary'];
            }

            $publicId = $uploadResult['public_id'] ?? $uploadResult['publicId'] ?? null;
            $url = $uploadResult['secure_url'] ?? $uploadResult['url'] ?? null;

            if (!$publicId || !$url) {
                return ['success' => false, 'error' => 'Réponse Cloudinary invalide'];
            }

            $media = new Media();
            $media->setCloudinaryPublicId((string) $publicId);
            $media->setUrl((string) $url);
            $media->setThumbnailUrl($this->cloudinaryService->generateThumbnailUrl((string) $publicId, 300, 300));
            $media->setAltText($file->getClientOriginalName());
            $media->setWidth(isset($uploadResult['width']) ? (int) $uploadResult['width'] : null);
            $media->setHeight(isset($uploadResult['height']) ? (int) $uploadResult['height'] : null);

            return ['success' => true, 'media' => $media];

        } catch (\Throwable $e) {
            $this->logger->error('uploadAndCreateMedia failed', [
                'file' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }


    

    /**
     * Dupliquer un produit
     * 
     * POST /api/admin/products/{id}/duplicate
     * 
     * Body (JSON - optionnel):
     * {
     *   "name": "Nom du produit dupliqué",
     *   "copyImages": true
     * }
     * 
     * Response:
     * {
     *   "success": true,
     *   "product": {...},
     *   "message": "Produit dupliqué avec succès"
     * }
     */
    #[Route('/{id}/duplicate', name: 'duplicate', methods: ['POST'])]
    public function duplicate(string $id, Request $request): JsonResponse
    {
        try {
            // Récupérer le produit original
            $uuid = Uuid::fromString($id);
            $originalProduct = $this->productRepository->find($uuid);

            if (!$originalProduct) {
                return $this->json([
                    'success' => false,
                    'error' => 'Produit introuvable'
                ], 404);
            }

            // Options de duplication
            $data = json_decode($request->getContent(), true) ?? [];
            $copyImages = $data['copyImages'] ?? true;
            $customName = $data['name'] ?? null;

            // Créer le nouveau produit
            $newProduct = new Product();

            // Copier les données de base
            $newName = $customName ?? $originalProduct->getName() . ' (Copie)';
            $newProduct->setName($newName);

            // Générer un slug unique
            $baseSlug = $this->slugger->slug($newName)->lower()->toString();
            $slug = $baseSlug;
            $counter = 1;
            while ($this->productRepository->findOneBy(['slug' => $slug])) {
                $slug = $baseSlug . '-' . $counter;
                $counter++;
            }
            $newProduct->setSlug($slug);

            // Copier les autres champs
            $newProduct->setCategory($originalProduct->getCategory());
            $newProduct->setDescription($originalProduct->getDescription());
            $newProduct->setPrice($originalProduct->getPrice());
            $newProduct->setOriginalPrice($originalProduct->getOriginalPrice());
            $newProduct->setStock(0); // Stock à 0 par défaut
            $newProduct->setWeightGrams($originalProduct->getWeightGrams());
            $newProduct->setBadge($originalProduct->getBadge());
            $newProduct->setBenefits($originalProduct->getBenefits());
            $newProduct->setIngredients($originalProduct->getIngredients());
            $newProduct->setUsage($originalProduct->getUsage());
            $newProduct->setIsEnabled(false); // Désactivé par défaut

            // Copier les images si demandé
            if ($copyImages && $originalProduct->getImageUrl()) {
                $newProduct->setImageUrl($originalProduct->getImageUrl());
                
                // TODO: Copier aussi les productMedias si nécessaire
                // Pour l'instant, juste l'image principale
            }

            // Persister
            $this->em->persist($newProduct);
            $this->em->flush();

            $this->logger->info('Product duplicated', [
                'original_id' => $originalProduct->getId()->toRfc4122(),
                'new_id' => $newProduct->getId()->toRfc4122(),
                'copy_images' => $copyImages,
            ]);

            return $this->json([
                'success' => true,
                'product' => [
                    'id' => $newProduct->getId()->toRfc4122(),
                    'slug' => $newProduct->getSlug(),
                    'name' => $newProduct->getName(),
                    'price' => $newProduct->getPrice(),
                    'stock' => $newProduct->getStock(),
                    'isEnabled' => $newProduct->getIsEnabled(),
                ],
                'message' => 'Produit dupliqué avec succès'
            ], 201);

        } catch (\Exception $e) {
            $this->logger->error('Product duplication failed', [
                'product_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la duplication : ' . $e->getMessage()
            ], 500);
        }
    }
}