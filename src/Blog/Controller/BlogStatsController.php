<?php

namespace App\Blog\Controller;

use App\Blog\Repository\BlogPostRepository;
use App\Blog\Repository\BlogCategoryRepository;
use App\Media\Service\CloudinaryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/blog')]
#[IsGranted('ROLE_ADMIN')]
class BlogStatsController extends AbstractController
{
    public function __construct(
        private BlogPostRepository $blogPostRepository,
        private BlogCategoryRepository $blogCategoryRepository,
        private CloudinaryService $cloudinaryService
    ) {}

    /**
     * Dashboard général - Toutes les stats en un seul endpoint
     * 
     * GET /api/blog/stats/dashboard
     */
    #[Route('/stats/dashboard', name: 'blog_stats_dashboard', methods: ['GET'])]
    public function dashboard(): JsonResponse
    {
        // 1. Statistiques des articles
        $postStats = $this->blogPostRepository->getStatistics();
        
        // 2. Statistiques par status
        $statusCounts = $this->blogPostRepository->countByStatus();
        
        // 3. Catégories avec nombre d'articles
        $categoriesWithCount = $this->blogCategoryRepository->findAllWithPostCount();
        $categories = array_map(function($result) {
            return [
                'id' => $result[0]->getId()->toRfc4122(),
                'name' => $result[0]->getName(),
                'slug' => $result[0]->getSlug(),
                'postCount' => $result['postCount'],
            ];
        }, $categoriesWithCount);
        
        // 4. Articles récents (7 derniers jours)
        $recentPosts = $this->blogPostRepository->findRecent(7);
        
        // 5. Articles à la une
        $featuredPosts = $this->blogPostRepository->findFeatured(5);
        
        // 6. Stats Cloudinary
        $cloudinaryStats = $this->cloudinaryService->getFolderStats('khamareo/blog');
        
        // 7. Articles par mois (derniers 6 mois)
        $articlesByMonth = $this->getArticlesByMonth(6);

        return $this->json([
            'overview' => [
                'totalArticles' => $postStats['totalArticles'],
                'totalPublished' => $postStats['totalPublished'],
                'totalDrafts' => $postStats['totalDrafts'],
                'totalFeatured' => $postStats['totalFeatured'],
                'lastPublishedAt' => $postStats['lastPublishedAt']?->format('c'),
            ],
            'statusBreakdown' => $statusCounts,
            'categories' => [
                'total' => count($categories),
                'list' => $categories,
            ],
            'recentActivity' => [
                'lastWeek' => count($recentPosts),
                'articles' => array_map([$this, 'formatPostSummary'], array_slice($recentPosts, 0, 5)),
            ],
            'featured' => array_map([$this, 'formatPostSummary'], $featuredPosts),
            'media' => [
                'totalImages' => $cloudinaryStats['success'] ? $cloudinaryStats['stats']['totalImages'] : 0,
                'totalSizeMB' => $cloudinaryStats['success'] ? $cloudinaryStats['stats']['totalSizeMB'] : 0,
                'formats' => $cloudinaryStats['success'] ? $cloudinaryStats['stats']['formats'] : [],
            ],
            'timeline' => $articlesByMonth,
        ]);
    }

    /**
     * Statistiques détaillées des articles
     * 
     * GET /api/blog/stats/posts
     */
    #[Route('/stats/posts', name: 'blog_stats_posts', methods: ['GET'])]
    public function postsStats(): JsonResponse
    {
        $stats = $this->blogPostRepository->getStatistics();
        $statusCounts = $this->blogPostRepository->countByStatus();
        
        // Articles par catégorie
        $categoriesWithCount = $this->blogCategoryRepository->findAllWithPostCount();
        $postsByCategory = array_map(function($result) {
            return [
                'category' => $result[0]->getName(),
                'count' => $result['postCount'],
            ];
        }, $categoriesWithCount);

        return $this->json([
            'total' => $stats['totalArticles'],
            'published' => $stats['totalPublished'],
            'drafts' => $stats['totalDrafts'],
            'featured' => $stats['totalFeatured'],
            'lastPublishedAt' => $stats['lastPublishedAt']?->format('c'),
            'byStatus' => $statusCounts,
            'byCategory' => $postsByCategory,
        ]);
    }

    /**
     * Statistiques des catégories
     * 
     * GET /api/blog/stats/categories
     */
    #[Route('/stats/categories', name: 'blog_stats_categories', methods: ['GET'])]
    public function categoriesStats(): JsonResponse
    {
        $categoriesWithCount = $this->blogCategoryRepository->findAllWithPostCount();
        
        $categories = array_map(function($result) {
            $category = $result[0];
            return [
                'id' => $category->getId()->toRfc4122(),
                'name' => $category->getName(),
                'slug' => $category->getSlug(),
                'description' => $category->getDescription(),
                'postCount' => $result['postCount'],
                'createdAt' => $category->getCreatedAt()->format('c'),
            ];
        }, $categoriesWithCount);

        // Trier par nombre d'articles (descendant)
        usort($categories, function($a, $b) {
            return $b['postCount'] <=> $a['postCount'];
        });

        return $this->json([
            'total' => count($categories),
            'withPosts' => count(array_filter($categories, fn($c) => $c['postCount'] > 0)),
            'empty' => count(array_filter($categories, fn($c) => $c['postCount'] === 0)),
            'categories' => $categories,
        ]);
    }

    /**
     * Timeline des publications (articles par mois)
     * 
     * GET /api/blog/stats/timeline?months=12
     */
    #[Route('/stats/timeline', name: 'blog_stats_timeline', methods: ['GET'])]
    public function timeline(Request $request): JsonResponse
    {
        $months = (int) $request->query->get('months', 12);
        $months = min(max($months, 1), 24); // Entre 1 et 24 mois
        
        $timeline = $this->getArticlesByMonth($months);

        return $this->json([
            'months' => $months,
            'data' => $timeline,
        ]);
    }

    /**
     * Statistiques des médias (Cloudinary)
     * 
     * GET /api/blog/stats/media
     */
    #[Route('/stats/media', name: 'blog_stats_media', methods: ['GET'])]
    public function mediaStats(): JsonResponse
    {
        $cloudinaryStats = $this->cloudinaryService->getFolderStats('khamareo/blog');

        if (!$cloudinaryStats['success']) {
            return $this->json([
                'error' => 'Impossible de récupérer les statistiques Cloudinary',
                'message' => $cloudinaryStats['error'] ?? 'Erreur inconnue'
            ], 500);
        }

        $stats = $cloudinaryStats['stats'];

        return $this->json([
            'totalImages' => $stats['totalImages'],
            'totalSize' => $stats['totalSize'],
            'totalSizeMB' => $stats['totalSizeMB'],
            'formats' => $stats['formats'],
            'tags' => $stats['tags'] ?? [],
            'averageSizeMB' => $stats['totalImages'] > 0 
                ? round($stats['totalSizeMB'] / $stats['totalImages'], 2) 
                : 0,
        ]);
    }

    /**
     * Articles récents (activité récente)
     * 
     * GET /api/blog/stats/recent?days=7
     */
    #[Route('/stats/recent', name: 'blog_stats_recent', methods: ['GET'])]
    public function recentActivity(Request $request): JsonResponse
    {
        $days = (int) $request->query->get('days', 7);
        $days = min(max($days, 1), 90); // Entre 1 et 90 jours
        
        $recentPosts = $this->blogPostRepository->findRecent($days);

        return $this->json([
            'days' => $days,
            'count' => count($recentPosts),
            'articles' => array_map([$this, 'formatPostSummary'], $recentPosts),
        ]);
    }

    /**
     * Top articles (par temps de lecture, par featured, etc.)
     * 
     * GET /api/blog/stats/top
     */
    #[Route('/stats/top', name: 'blog_stats_top', methods: ['GET'])]
    public function topArticles(): JsonResponse
    {
        // Top featured
        $featured = $this->blogPostRepository->findFeatured(10);
        
        // Plus longs (temps de lecture)
        $longest = $this->blogPostRepository->createQueryBuilder('bp')
            ->where('bp.status = :status')
            ->setParameter('status', 'published')
            ->orderBy('bp.readingTime', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
        
        // Plus récents
        $newest = $this->blogPostRepository->findPublished(10);

        return $this->json([
            'featured' => array_map([$this, 'formatPostSummary'], $featured),
            'longest' => array_map([$this, 'formatPostSummary'], $longest),
            'newest' => array_map([$this, 'formatPostSummary'], $newest),
        ]);
    }

    /**
     * Statistiques rapides (KPIs)
     * 
     * GET /api/blog/stats/kpis
     */
    #[Route('/stats/kpis', name: 'blog_stats_kpis', methods: ['GET'])]
    public function kpis(): JsonResponse
    {
        $stats = $this->blogPostRepository->getStatistics();
        $recentPosts = $this->blogPostRepository->findRecent(7);
        $cloudinaryStats = $this->cloudinaryService->getFolderStats('khamareo/blog');

        return $this->json([
            [
                'label' => 'Articles publiés',
                'value' => $stats['totalPublished'],
                'icon' => 'FileText',
                'color' => 'green',
            ],
            [
                'label' => 'Brouillons',
                'value' => $stats['totalDrafts'],
                'icon' => 'Edit',
                'color' => 'yellow',
            ],
            [
                'label' => 'À la une',
                'value' => $stats['totalFeatured'],
                'icon' => 'Star',
                'color' => 'purple',
            ],
            [
                'label' => 'Cette semaine',
                'value' => count($recentPosts),
                'icon' => 'TrendingUp',
                'color' => 'blue',
            ],
            [
                'label' => 'Images',
                'value' => $cloudinaryStats['success'] ? $cloudinaryStats['stats']['totalImages'] : 0,
                'icon' => 'Image',
                'color' => 'indigo',
            ],
            [
                'label' => 'Stockage',
                'value' => $cloudinaryStats['success'] 
                    ? number_format($cloudinaryStats['stats']['totalSizeMB'], 1) . ' MB'
                    : '0 MB',
                'icon' => 'HardDrive',
                'color' => 'gray',
            ],
        ]);
    }

    /**
     * Helper : Récupère les articles par mois
     */
    private function getArticlesByMonth(int $months): array
    {
        $connection = $this->blogPostRepository->createQueryBuilder('bp')
            ->getEntityManager()
            ->getConnection();

        $sql = "
            SELECT 
                TO_CHAR(published_at, 'YYYY-MM') as month,
                COUNT(*) as count
            FROM blog_post
            WHERE status = 'published'
                AND published_at >= NOW() - INTERVAL '{$months} months'
            GROUP BY TO_CHAR(published_at, 'YYYY-MM')
            ORDER BY month DESC
        ";

        $result = $connection->executeQuery($sql)->fetchAllAssociative();

        return array_map(function($row) {
            return [
                'month' => $row['month'],
                'count' => (int) $row['count'],
            ];
        }, $result);
    }

    /**
     * Helper : Formatte un article pour l'API
     */
    private function formatPostSummary($post): array
    {
        return [
            'id' => $post->getId()->toRfc4122(),
            'title' => $post->getTitle(),
            'slug' => $post->getSlug(),
            'status' => $post->getStatus(),
            'isFeatured' => $post->isFeatured(),
            'readingTime' => $post->getReadingTime(),
            'publishedAt' => $post->getPublishedAt()?->format('c'),
            'createdAt' => $post->getCreatedAt()->format('c'),
            'category' => $post->getCategory() ? [
                'id' => $post->getCategory()->getId()->toRfc4122(),
                'name' => $post->getCategory()->getName(),
                'slug' => $post->getCategory()->getSlug(),
            ] : null,
            'authorName' => $post->getAuthorName(),
            'featuredImage' => $post->getFeaturedImage(),
        ];
    }
}