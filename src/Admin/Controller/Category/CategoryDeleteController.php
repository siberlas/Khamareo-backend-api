<?php

namespace App\Admin\Controller\Category;

use App\Catalog\Entity\Category;
use App\Catalog\Repository\CategoryRepository;
use App\Catalog\Repository\ProductRepository;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use App\Media\Entity\CategoryMedia;
use App\Media\Entity\Media;
use App\Media\Entity\ProductMedia;
use App\Media\Service\CloudinaryService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Uid\Uuid;

#[AsController]
#[Route('/api/admin/categories', name: 'admin_categories_delete_')]
class CategoryDeleteController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private CategoryRepository $categoryRepository,
        private ProductRepository $productRepository,
        private CloudinaryService $cloudinaryService,
        private LoggerInterface $logger,
        private TagAwareCacheInterface $catalogCache,
    ) {}

    /**
     * DELETE /api/admin/categories/{id}
     *
     * Règles métier :
     * - Interdit si enfants
     * - Interdit si produits
     * - Autorisé seulement si 0 enfant et 0 produit
     */
    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function delete(string $id): JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);

            /** @var Category|null $category */
            $category = $this->categoryRepository->createQueryBuilder('c')
                ->leftJoin('c.children', 'ch')->addSelect('ch')
                ->leftJoin('c.categoryMedias', 'cm')->addSelect('cm')
                ->leftJoin('cm.media', 'm')->addSelect('m')
                ->where('c.id = :id')
                ->setParameter('id', $uuid)
                ->getQuery()
                ->getOneOrNullResult();

            if (!$category) {
                return $this->json([
                    'success' => false,
                    'error' => 'Catégorie introuvable'
                ], 404);
            }

            // 1) Vérifier enfants
            $childrenCount = $category->getChildren()->count();
            if ($childrenCount > 0) {
                return $this->json([
                    'success' => false,
                    'error' => sprintf(
                        "Suppression impossible : cette catégorie contient %d sous-catégorie(s). Conditions de suppression : aucune sous-catégorie et aucun produit.",
                        $childrenCount
                    ),
                    'conditions' => [
                        'mustHaveNoChildren' => true,
                        'mustHaveNoProducts' => true,
                    ],
                    'counts' => [
                        'children' => $childrenCount,
                    ],
                ], 400);
            }

            // 2) Vérifier produits (FIABLE car basé sur la relation Product.category non nullable)
            $productsCount = (int) $this->productRepository->count(['category' => $category]);
            if ($productsCount > 0) {
                return $this->json([
                    'success' => false,
                    'error' => sprintf(
                        "Suppression impossible : cette catégorie contient %d produit(s). Conditions de suppression : aucun produit et aucune sous-catégorie.",
                        $productsCount
                    ),
                    'conditions' => [
                        'mustHaveNoChildren' => true,
                        'mustHaveNoProducts' => true,
                    ],
                    'counts' => [
                        'products' => $productsCount,
                    ],
                ], 400);
            }

            // 3) OK => supprimer médias + catégorie
            $this->em->wrapInTransaction(function () use ($category) {
                $this->deleteCategoryMediasAndOrphanMedias($category);
                $this->em->remove($category);
            });
            $this->catalogCache->invalidateTags([CategoryRepository::CACHE_TAG]);

            $this->logger->info('Category deleted', [
                'category_id' => $id,
            ]);

            return $this->json([
                'success' => true,
                'message' => 'Catégorie supprimée avec succès'
            ], 200);

        } catch (\Exception $e) {
            $this->logger->error('Category delete failed', [
                'category_id' => $id,
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
     * Supprime les CategoryMedia et supprime les Media uniquement si non utilisés ailleurs.
     * Nettoie Cloudinary uniquement quand on supprime réellement le Media.
     */
    private function deleteCategoryMediasAndOrphanMedias(Category $category): void
    {
        $categoryMedias = $category->getCategoryMedias()->toArray(); // copie

        foreach ($categoryMedias as $categoryMedia) {
            if (!$categoryMedia instanceof CategoryMedia) {
                continue;
            }

            $media = $categoryMedia->getMedia();

            // On supprime d'abord la liaison CategoryMedia
            $this->em->remove($categoryMedia);

            if (!$media instanceof Media) {
                continue;
            }

            // Si le media est utilisé ailleurs, on ne le supprime pas
            if ($this->isMediaUsedElsewhere($media->getId()->toRfc4122())) {
                continue;
            }

            // Sinon: supprimer Cloudinary + Media
            $publicId = $media->getCloudinaryPublicId();
            try {
                $this->cloudinaryService->deleteAsset($publicId, 'image', true);
            } catch (\Exception $e) {
                // Best-effort: on ne bloque pas la suppression DB
                $this->logger->warning('Cloudinary delete failed during category delete', [
                    'public_id' => $publicId,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->em->remove($media);
        }
    }

    /**
     * Vérifie si un Media est encore référencé par d'autres tables:
     * - category_media
     * - product_media
     *
     * Si tu as d'autres pivots (hero slides etc.), ajoute-les ici.
     */
    private function isMediaUsedElsewhere(string $mediaId): bool
    {
        $uuid = Uuid::fromString($mediaId);

        $categoryMediaRefs = (int) $this->em->createQueryBuilder()
            ->select('COUNT(cm.id)')
            ->from(CategoryMedia::class, 'cm')
            ->where('cm.media = :mid')
            ->setParameter('mid', $uuid)
            ->getQuery()
            ->getSingleScalarResult();

        $productMediaRefs = (int) $this->em->createQueryBuilder()
            ->select('COUNT(pm.id)')
            ->from(ProductMedia::class, 'pm')
            ->where('pm.media = :mid')
            ->setParameter('mid', $uuid)
            ->getQuery()
            ->getSingleScalarResult();

        // Si tu as d'autres relations vers Media, ajoute d'autres compteurs
        return ($categoryMediaRefs + $productMediaRefs) > 0;
    }
}
