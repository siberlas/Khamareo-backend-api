<?php

declare(strict_types=1);

namespace App\Catalog\Controller;

use App\Catalog\Entity\Category;
use App\Catalog\Entity\Product;
use App\Blog\Entity\BlogPost;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
class SearchSuggestionsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RateLimiterFactory $searchLimiter,
    ) {}

    #[Route('/api/search/suggestions', name: 'api_search_suggestions', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $limiter = $this->searchLimiter->create($request->getClientIp() ?? 'unknown');
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Too many requests'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $query = trim((string) $request->query->get('q', ''));

        if (strlen($query) < 2) {
            $products    = $this->getFeaturedProducts(4);
            $categories  = $this->getAllCategories();
            $posts       = $this->getFeaturedPosts(3);
        } else {
            $products    = $this->searchProducts($query, 4);
            $categories  = $this->searchCategories($query, 3);
            $posts       = $this->searchPosts($query, 3);
        }

        $response = new JsonResponse([
            'products'   => $products,
            'categories' => $categories,
            'posts'      => $posts,
            'total'      => count($products) + count($categories) + count($posts),
        ]);
        $response->headers->set('Cache-Control', 'public, max-age=60');

        return $response;
    }

    /**
     * Blog posts search (published only)
     * @return array<int, array<string, mixed>>
     */
    private function searchPosts(string $query, int $limit): array
    {
        $q  = strtolower($query);
        $qb = $this->em->createQueryBuilder();

        $qb->select('bp', 'cat')
            ->from(BlogPost::class, 'bp')
            ->leftJoin('bp.category', 'cat')
            ->where('bp.status = :status')
            ->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('LOWER(bp.title)', ':q'),
                    $qb->expr()->like('LOWER(bp.excerpt)', ':q'),
                    $qb->expr()->like('LOWER(bp.content)', ':q')
                )
            )
            ->addSelect(
                'CASE WHEN LOWER(bp.title) = :exact THEN 0 WHEN LOWER(bp.title) LIKE :qstart THEN 1 ELSE 2 END AS HIDDEN sortOrder'
            )
            ->setParameter('status', 'published')
            ->setParameter('q', '%' . $q . '%')
            ->setParameter('exact', $q)
            ->setParameter('qstart', $q . '%')
            ->orderBy('sortOrder', 'ASC')
            ->addOrderBy('bp.publishedAt', 'DESC')
            ->setMaxResults($limit);

        return array_map(
            fn (BlogPost $p) => $this->formatPost($p),
            $qb->getQuery()->getResult()
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function getFeaturedPosts(int $limit): array
    {
        $qb = $this->em->createQueryBuilder();

        $qb->select('bp', 'cat')
            ->from(BlogPost::class, 'bp')
            ->leftJoin('bp.category', 'cat')
            ->where('bp.status = :status')
            ->andWhere('bp.isFeatured = :featured')
            ->setParameter('status', 'published')
            ->setParameter('featured', true)
            ->orderBy('bp.publishedAt', 'DESC')
            ->setMaxResults($limit);

        return array_map(
            fn (BlogPost $p) => $this->formatPost($p),
            $qb->getQuery()->getResult()
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function searchProducts(string $query, int $limit): array
    {
        $q  = strtolower($query);
        $qb = $this->em->createQueryBuilder();

        $qb->select('p', 'cat')
            ->from(Product::class, 'p')
            ->leftJoin('p.category', 'cat')
            ->where('p.isEnabled = :enabled')
            ->andWhere('p.isDeleted = :deleted')
            ->andWhere('cat.isEnabled = :enabled')
            ->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('LOWER(p.name)', ':q'),
                    $qb->expr()->like('LOWER(p.description)', ':q'),
                    $qb->expr()->like('LOWER(cat.name)', ':q'),
                )
            )
            ->addSelect(
                'CASE WHEN LOWER(p.name) = :exact THEN 0 WHEN LOWER(p.name) LIKE :qstart THEN 1 ELSE 2 END AS HIDDEN sortOrder'
            )
            ->setParameter('enabled', true)
            ->setParameter('deleted', false)
            ->setParameter('q', '%' . $q . '%')
            ->setParameter('exact', $q)
            ->setParameter('qstart', $q . '%')
            ->orderBy('sortOrder', 'ASC')
            ->addOrderBy('p.name', 'ASC')
            ->setMaxResults($limit);

        return array_map(
            fn (Product $p) => $this->formatProduct($p),
            $qb->getQuery()->getResult()
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function getFeaturedProducts(int $limit): array
    {
        $qb = $this->em->createQueryBuilder();

        $qb->select('p')
            ->from(Product::class, 'p')
            ->leftJoin('p.category', 'cat')
            ->where('p.isEnabled = :enabled')
            ->andWhere('p.isDeleted = :deleted')
            ->andWhere('p.isFeatured = :featured')
            ->andWhere('cat.isEnabled = :enabled')
            ->setParameter('enabled', true)
            ->setParameter('deleted', false)
            ->setParameter('featured', true)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit);

        return array_map(
            fn (Product $p) => $this->formatProduct($p),
            $qb->getQuery()->getResult()
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function searchCategories(string $query, int $limit): array
    {
        $q  = strtolower($query);
        $qb = $this->em->createQueryBuilder();

        $qb->select('c')
            ->from(Category::class, 'c')
            ->leftJoin('c.parent', 'parent')
            ->where('c.isEnabled = :enabled')
            ->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('LOWER(c.name)', ':q'),
                    $qb->expr()->like('LOWER(c.description)', ':q'),
                )
            )
            ->addSelect(
                'CASE WHEN LOWER(c.name) = :exact THEN 0 ELSE 1 END AS HIDDEN sortOrder'
            )
            ->setParameter('enabled', true)
            ->setParameter('q', '%' . $q . '%')
            ->setParameter('exact', $q)
            ->orderBy('sortOrder', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->setMaxResults($limit);

        return array_map(
            fn (Category $c) => $this->formatCategory($c),
            $qb->getQuery()->getResult()
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function getAllCategories(): array
    {
        $qb = $this->em->createQueryBuilder();

        $qb->select('c')
            ->from(Category::class, 'c')
            ->where('c.isEnabled = :enabled')
            ->setParameter('enabled', true)
            ->orderBy('c.displayOrder', 'ASC')
            ->addOrderBy('c.name', 'ASC');

        return array_map(
            fn (Category $c) => $this->formatCategory($c),
            $qb->getQuery()->getResult()
        );
    }

    /** @return array<string, mixed> */
    private function formatProduct(Product $p): array
    {
        $cat = $p->getCategory();

        return [
            'id'           => (string) $p->getId(),
            'name'         => $p->getName(),
            'slug'         => $p->getSlug(),
            'category'     => $cat?->getName(),
            'price'        => $p->getPrice(),
            'currency'     => 'EUR',
            'thumbnailUrl' => $p->getImageUrl(),
        ];
    }

    /** @return array<string, mixed> */
    private function formatCategory(Category $c): array
    {
        $parent = $c->getParent();

        return [
            'name'         => $c->getName(),
            'slug'         => $c->getSlug(),
            'parentSlug'   => $parent?->getSlug(),
            'productCount' => $c->getProductsCount(),
        ];
    }

    /** @return array<string, mixed> */
    private function formatPost(BlogPost $post): array
    {
        $cat = $post->getCategory();

        return [
            'id'          => $post->getId()?->toRfc4122(),
            'title'       => $post->getTitle(),
            'slug'        => $post->getSlug(),
            'excerpt'     => $post->getExcerpt(),
            'featuredImage' => $post->getFeaturedImage(),
            'isFeatured'  => $post->isFeatured(),
            'publishedAt' => $post->getPublishedAt()?->format('c'),
            'category'    => $cat?->getName(),
            'categorySlug'=> $cat?->getSlug(),
            'readingTime' => method_exists($post, 'getReadingTime') ? $post->getReadingTime() : null,
        ];
    }
}
