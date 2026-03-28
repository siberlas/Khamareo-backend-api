<?php

namespace App\Admin\Controller\Product;

use App\Catalog\Entity\Product;
use App\Catalog\Repository\CategoryRepository;
use App\Media\Entity\Media;
use App\Media\Entity\ProductMedia;
use App\Media\Service\CloudinaryService;
use App\Catalog\Entity\Badge;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\String\Slugger\SluggerInterface;

#[AsController]
#[Route('/api/admin/products', name: 'admin_products_')]
class ProductCreationController extends AbstractController
{
    private const MAX_IMAGE_SIZE_MB = 10;
    
    private const MAX_IMAGE_SIZE_BYTES = self::MAX_IMAGE_SIZE_MB * 1024 * 1024;
    public function __construct(
        private EntityManagerInterface $em,
        private CloudinaryService $cloudinaryService,
        private CategoryRepository $categoryRepository,
        private SluggerInterface $slugger,
        private LoggerInterface $logger
    ) {}

    /**
     * Créer un produit avec images en une seule requête
     * 
     * POST /api/admin/products/create-with-images
     * 
     * FormData:
     *   name: string (requis)
     *   slug: string (optionnel, généré auto si absent)
     *   categoryId: uuid (requis)
     *   description: string (optionnel)
     *   price: float (requis)
     *   originalPrice: float (optionnel)
     *   stock: int (requis)
     *   weightGrams: int (optionnel)
     *   badge: string (optionnel)
     *   benefits[]: string[] (optionnel)
     *   ingredients: string (optionnel)
     *   usage: string (optionnel)
    *   isFeatured: bool (optionnel)
     *   mainImage: File (requis - image principale, max 20 MB)
     *   galleryImages[]: File[] (optionnel - images galerie, max 20 MB chacune)
     */
    #[Route('/create-with-images', name: 'create_with_images', methods: ['POST'])]
    public function createWithImages(Request $request): JsonResponse
    {
        try {
            // 1. VALIDATION DES DONNÉES REQUISES
            $name = $request->request->get('name');
            $categoryId = $request->request->get('categoryId');
            $price = $request->request->get('price');
            $stock = $request->request->get('stock');
            $badgeId = $request->request->get('badgeId');
            $isEnabled = $request->request->get('isEnabled');
            $isFeatured = $request->request->get('isFeatured');

            if (!$name || !$categoryId || !$price || $stock === null) {
                return $this->json([
                    'success' => false,
                    'error' => 'Champs requis manquants : name, categoryId, price, stock'
                ], 400);
            }

            // 2. RÉCUPÉRER LA CATÉGORIE
            $category = $this->categoryRepository->find($categoryId);
            if (!$category) {
                return $this->json([
                    'success' => false,
                    'error' => 'Catégorie introuvable'
                ], 404);
            }

            // 3. CRÉER LE PRODUIT
            $product = new Product();
            $product->setName($name);
            
            // Générer le slug
            $slug = $request->request->get('slug');
            if (!$slug) {
                $slug = $this->slugger->slug($name)->lower()->toString();
            }
            $product->setSlug($slug);
            
            $product->setCategory($category);
            $product->setDescription($request->request->get('description'));
            $product->setPrice((float) $price);
            $product->setStock((int) $stock);

            if ($badgeId) {
                $badge = $this->em->getRepository(Badge::class)->find($badgeId);
                if (!$badge) {
                    return $this->json([
                        'success' => false,
                        'error' => 'Badge introuvable'
                    ], 404);
                }
                $product->setBadge($badge);
            } else {
                $product->setBadge(null);
            }
            
            // Champs optionnels
            if ($originalPrice = $request->request->get('originalPrice')) {
                $product->setOriginalPrice((float) $originalPrice);
            }
            if ($weightGrams = $request->request->get('weightGrams')) {
                $product->setWeightGrams((int) $weightGrams);
            }
            if ($benefits = $request->request->all('benefits')) {
                $product->setBenefits($benefits);
            }
            if ($ingredients = $request->request->get('ingredients')) {
                $product->setIngredients($ingredients);
            }
            if ($usage = $request->request->get('usage')) {
                $product->setUsage($usage);
            }
            if ($preparation = $request->request->get('preparation')) {
                $product->setPreparation($preparation);
            }
            if ($faqJson = $request->request->get('faq')) {
                $faq = json_decode($faqJson, true);
                if (is_array($faq)) {
                    $product->setFaq($faq);
                }
            }
            if ($isEnabled !== null) {
                $product->setIsEnabled(filter_var($isEnabled, FILTER_VALIDATE_BOOLEAN));
            }
            if ($isFeatured !== null) {
                $isFeaturedBool = filter_var($isFeatured, FILTER_VALIDATE_BOOLEAN);
                if ($isFeaturedBool) {
                    $featuredCount = $this->em->getRepository(Product::class)->count([
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
                $product->setIsFeatured($isFeaturedBool);
            }

            // 4. UPLOADER ET ATTACHER L'IMAGE PRINCIPALE
            /** @var UploadedFile|null $mainImage */
            $mainImage = $request->files->get('mainImage');
            
            if ($mainImage) {
                $uploadResult = $this->uploadAndCreateMedia($mainImage, true);
                
                if ($uploadResult['success']) {
                    $media = $uploadResult['media'];
                    
                    // Créer ProductMedia pour l'image principale
                    $productMedia = new ProductMedia();
                    $productMedia->setProduct($product);
                    $productMedia->setMedia($media);
                    $productMedia->setIsPrimary(true);
                    $productMedia->setDisplayOrder(0);
                    
                    $product->addProductMedia($productMedia);
                    
                    // Aussi mettre dans imageUrl pour compatibilité
                    $product->setImageUrl($media->getUrl());
                    
                    $this->em->persist($media);
                    $this->em->persist($productMedia);
                } else {
                    return $this->json([
                        'success' => false,
                        'error' => 'Erreur upload image principale : ' . $uploadResult['error']
                    ], 500);
                }
            } else {
                return $this->json([
                    'success' => false,
                    'error' => 'Image principale requise'
                ], 400);
            }

            // 5. UPLOADER ET ATTACHER LES IMAGES DE LA GALERIE
            $galleryImages = $request->files->all('galleryImages');
            
            if (!empty($galleryImages)) {
                $displayOrder = 1; // Commence à 1 car l'image principale est à 0
                
                foreach ($galleryImages as $galleryImage) {
                    if (!$galleryImage instanceof UploadedFile) {
                        continue;
                    }
                    
                    $uploadResult = $this->uploadAndCreateMedia($galleryImage, false);
                    
                    if ($uploadResult['success']) {
                        $media = $uploadResult['media'];
                        
                        $productMedia = new ProductMedia();
                        $productMedia->setProduct($product);
                        $productMedia->setMedia($media);
                        $productMedia->setIsPrimary(false);
                        $productMedia->setDisplayOrder($displayOrder);
                        
                        $product->addProductMedia($productMedia);
                        
                        $this->em->persist($media);
                        $this->em->persist($productMedia);
                        
                        $displayOrder++;
                    }
                }
            }

            // 6. PERSISTER LE PRODUIT
            $this->em->persist($product);

            // 7. GÉRER LES PRODUITS LIÉS (optionnel)
            $relatedProductIds = $request->request->all('relatedProductIds');

            if (!empty($relatedProductIds) && is_array($relatedProductIds)) {
                foreach ($relatedProductIds as $relatedId) {
                    try {
                        $relatedUuid = \Symfony\Component\Uid\Uuid::fromString($relatedId);
                        $relatedProduct = $this->em->getRepository(Product::class)->find($relatedUuid);
                        
                        if ($relatedProduct && $relatedProduct->getId() !== $product->getId()) {
                            $product->addRelatedProduct($relatedProduct);
                        }
                    } catch (\Exception $e) {
                        // Skip invalid UUID
                        continue;
                    }
                }
            }
            
            $this->em->flush();

            $this->logger->info('Product created with images', [
                'product_id' => $product->getId()->toRfc4122(),
                'slug' => $product->getSlug(),
                'images_count' => $product->getProductMedias()->count(),
            ]);

            // 7. RETOURNER LA RÉPONSE
            return $this->json([
                'success' => true,
                'product' => [
                    'id' => $product->getId()->toRfc4122(),
                    'slug' => $product->getSlug(),
                    'name' => $product->getName(),
                    'price' => $product->getPrice(),
                    'stock' => $product->getStock(),
                    'primaryImage' => $product->getPrimaryMedia()?->getUrl(),
                    'imagesCount' => $product->getProductMedias()->count(),
                ],
                'message' => 'Produit créé avec succès'
            ], 201);

        } catch (\Exception $e) {
            $this->logger->error('Product creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la création du produit : ' . $e->getMessage()
            ], 500);
        }
    }

    /**
    * Upload une image vers Cloudinary et créer l'entité Media
    *
    * @param UploadedFile $file Fichier à uploader
    * @param bool $isPrimary Si c'est l'image principale
    * @return array Résultat avec 'success', 'media' ou 'error'
    */
    private function uploadAndCreateMedia(UploadedFile $file, bool $isPrimary = false): array
    {
        try {
            // Validation format
            $allowedMimeTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
            $mimeType = (string) $file->getMimeType();

            if (!in_array($mimeType, $allowedMimeTypes, true)) {
                return [
                    'success' => false,
                    'error' => 'Format non autorisé : ' . $mimeType . '. Formats acceptés : JPG, PNG, WEBP, GIF'
                ];
            }

            // Validation taille : 20 MB maximum
            $maxSize = self::MAX_IMAGE_SIZE_BYTES; // 20 MB
            $size = (int) $file->getSize();

            if ($size > $maxSize) {
                return [
                    'success' => false,
                    'error' => sprintf(
                        'Fichier trop volumineux : %s. Taille maximum : 20 MB',
                        $this->formatBytes($size)
                    )
                ];
            }

            // Upload vers Cloudinary dans le dossier khamareo/products
            // ✅ "folder" est la clé standard Cloudinary (asset_folder peut être ignoré selon implémentation)
            $uploadOptions = [
                'folder' => 'khamareo/products',
                'tags' => ['product'],
                'resource_type' => 'image',
            ];

            $uploadResult = $this->cloudinaryService->uploadImage(
                $file->getPathname(),
                $uploadOptions
            );

            if (empty($uploadResult['success'])) {
                return [
                    'success' => false,
                    'error' => $uploadResult['error'] ?? 'Erreur lors de l\'upload sur Cloudinary'
                ];
            }

            // ✅ Récupération du public_id Cloudinary (obligatoire chez toi en BDD)
            $publicId = $uploadResult['public_id'] ?? $uploadResult['publicId'] ?? null;
            if (!$publicId) {
                return [
                    'success' => false,
                    'error' => 'Réponse Cloudinary invalide : public_id manquant'
                ];
            }

            // URL (souvent secure_url)
            $url = $uploadResult['secure_url'] ?? $uploadResult['url'] ?? null;
            if (!$url) {
                return [
                    'success' => false,
                    'error' => 'Réponse Cloudinary invalide : url/secure_url manquante'
                ];
            }

            // Créer l'entité Media
            $media = new Media();
            $media->setCloudinaryPublicId((string) $publicId); // ✅ FIX principal
            $media->setUrl((string) $url);

            // Générer thumbnail 300x300
            $media->setThumbnailUrl(
                $this->cloudinaryService->generateThumbnailUrl((string) $publicId, 300, 300)
            );

            $media->setAltText($file->getClientOriginalName());
            $media->setWidth(isset($uploadResult['width']) ? (int) $uploadResult['width'] : null);
            $media->setHeight(isset($uploadResult['height']) ? (int) $uploadResult['height'] : null);

            return [
                'success' => true,
                'media' => $media,
                'uploadResult' => $uploadResult,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Image upload and media creation failed', [
                'filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Erreur technique : ' . $e->getMessage()
            ];
        }
    }


    /**
     * Ajouter des images à un produit existant
     * 
     * POST /api/admin/products/{slug}/add-images
     * 
     * FormData:
     *   images[]: File[] (max 20 MB chacune)
     *   setAsPrimary: boolean (si true, la première image devient principale)
     */
    #[Route('/{slug}/add-images', name: 'add_images', methods: ['POST'])]
    public function addImages(string $slug, Request $request): JsonResponse
    {
        try {
            // Récupérer le produit
            $product = $this->em->getRepository(Product::class)->findOneBy(['slug' => $slug]);
            
            if (!$product) {
                return $this->json([
                    'success' => false,
                    'error' => 'Produit introuvable'
                ], 404);
            }

            $images = $request->files->all('images');
            
            if (empty($images)) {
                return $this->json([
                    'success' => false,
                    'error' => 'Aucune image fournie'
                ], 400);
            }

            $setAsPrimary = $request->request->getBoolean('setAsPrimary', false);
            
            // Déterminer l'ordre de départ
            $maxOrder = 0;
            foreach ($product->getProductMedias() as $pm) {
                if ($pm->getDisplayOrder() > $maxOrder) {
                    $maxOrder = $pm->getDisplayOrder();
                }
            }
            
            $displayOrder = $maxOrder + 1;
            $uploadedCount = 0;
            $firstImage = true;

            foreach ($images as $image) {
                if (!$image instanceof UploadedFile) {
                    continue;
                }

                $uploadResult = $this->uploadAndCreateMedia($image, false);

                if ($uploadResult['success']) {
                    $media = $uploadResult['media'];

                    // Si setAsPrimary ET première image, retirer isPrimary des autres
                    if ($setAsPrimary && $firstImage) {
                        foreach ($product->getProductMedias() as $pm) {
                            $pm->setIsPrimary(false);
                        }
                    }

                    $productMedia = new ProductMedia();
                    $productMedia->setProduct($product);
                    $productMedia->setMedia($media);
                    $productMedia->setIsPrimary($setAsPrimary && $firstImage);
                    $productMedia->setDisplayOrder($displayOrder);

                    $product->addProductMedia($productMedia);

                    // Mettre à jour imageUrl si c'est la nouvelle image principale
                    if ($setAsPrimary && $firstImage) {
                        $product->setImageUrl($media->getUrl());
                    }

                    $this->em->persist($media);
                    $this->em->persist($productMedia);

                    $displayOrder++;
                    $uploadedCount++;
                    $firstImage = false;
                }
            }

            $this->em->flush();

            $this->logger->info('Images added to product', [
                'product_slug' => $slug,
                'uploaded_count' => $uploadedCount,
            ]);

            return $this->json([
                'success' => true,
                'uploaded' => $uploadedCount,
                'totalImages' => $product->getProductMedias()->count(),
                'message' => sprintf('%d image(s) ajoutée(s) avec succès', $uploadedCount)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Add images failed', [
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de l\'ajout des images : ' . $e->getMessage()
            ], 500);
        }
    }

   /**
     * Supprimer une image d'un produit
     * 
     * DELETE /api/admin/products/{slug}/images/{productMediaId}
     */
    #[Route('/{slug}/images/{productMediaId}', name: 'remove_image', methods: ['DELETE'])]
    public function removeImage(string $slug, string $productMediaId): JsonResponse
    {
        try {
            $product = $this->em->getRepository(Product::class)->findOneBy(['slug' => $slug]);
            
            if (!$product) {
                return $this->json([
                    'success' => false,
                    'error' => 'Produit introuvable'
                ], 404);
            }

            // Convertir l'ID en UUID
            try {
                $uuid = \Symfony\Component\Uid\Uuid::fromString($productMediaId);
            } catch (\Exception $e) {
                return $this->json([
                    'success' => false,
                    'error' => 'ID d\'image invalide'
                ], 400);
            }

            $productMedia = $this->em->getRepository(ProductMedia::class)->find($uuid);
            
            if (!$productMedia || $productMedia->getProduct() !== $product) {
                return $this->json([
                    'success' => false,
                    'error' => 'Image introuvable'
                ], 404);
            }

            // Récupérer le Media et son publicId Cloudinary
            $media = $productMedia->getMedia();
            $cloudinaryPublicId = $media?->getCloudinaryPublicId();

            // Supprimer ProductMedia
            $this->em->remove($productMedia);
            
            // Supprimer Media (si orphanRemoval n'est pas configuré)
            if ($media) {
                $this->em->remove($media);
            }
            
            $this->em->flush();

            // Supprimer de Cloudinary
            if ($cloudinaryPublicId) {
                $deleteResult = $this->cloudinaryService->deleteAsset(
                    $cloudinaryPublicId,
                    'image',
                    true // invalidate CDN
                );

                if (empty($deleteResult['success'])) {
                    $this->logger->warning('Cloudinary image deletion failed', [
                        'publicId' => $cloudinaryPublicId,
                        'error' => $deleteResult['error'] ?? 'unknown',
                    ]);
                    // On continue quand même, l'image est supprimée de la BDD
                }
            }

            $this->logger->info('Product image removed', [
                'product_slug' => $slug,
                'product_media_id' => $productMediaId,
                'cloudinary_deleted' => !empty($deleteResult['success']),
            ]);

            return $this->json([
                'success' => true,
                'message' => 'Image supprimée avec succès'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Remove image failed', [
                'slug' => $slug,
                'product_media_id' => $productMediaId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la suppression : ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Formate une taille en bytes vers un format lisible
     * 
     * @param int $bytes Taille en bytes
     * @return string Taille formatée (ex: "15.3 MB")
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}