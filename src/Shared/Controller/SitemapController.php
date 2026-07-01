<?php

namespace App\Shared\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
class SitemapController extends AbstractController
{
    public function __construct(
        private Connection $db,
        #[Autowire('%env(FRONTEND_BASE_URL)%')]
        private string $frontendBaseUrl,
    ) {}

    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'])]
    public function __invoke(): Response
    {
        $baseUrl = rtrim($this->frontendBaseUrl, '/');
        $today   = (new \DateTimeImmutable())->format('Y-m-d');

        // Pages statiques
        $staticPages = [
            ['loc' => '/',             'priority' => '1.0', 'changefreq' => 'daily',   'lastmod' => $today],
            ['loc' => '/boutique',     'priority' => '0.9', 'changefreq' => 'daily',   'lastmod' => $today],
            ['loc' => '/blog',         'priority' => '0.8', 'changefreq' => 'weekly',  'lastmod' => $today],
            ['loc' => '/about',        'priority' => '0.6', 'changefreq' => 'monthly', 'lastmod' => $today],
            ['loc' => '/contact',      'priority' => '0.5', 'changefreq' => 'monthly', 'lastmod' => $today],
            ['loc' => '/terms',        'priority' => '0.3', 'changefreq' => 'yearly',  'lastmod' => $today],
            ['loc' => '/privacy',      'priority' => '0.3', 'changefreq' => 'yearly',  'lastmod' => $today],
            ['loc' => '/retractation', 'priority' => '0.3', 'changefreq' => 'yearly',  'lastmod' => $today],
        ];

        // Produits actifs
        $products = $this->db->executeQuery(
            "SELECT slug, COALESCE(updated_at, created_at) AS lastmod
             FROM product
             WHERE is_enabled = true AND is_deleted = false
             ORDER BY updated_at DESC"
        )->fetchAllAssociative();

        // Catégories actives (racines uniquement pour éviter les doublons)
        $categories = $this->db->executeQuery(
            "SELECT slug, COALESCE(updated_at, created_at) AS lastmod
             FROM category
             WHERE is_enabled = true
             ORDER BY display_order"
        )->fetchAllAssociative();

        // Articles de blog publiés
        $blogPosts = $this->db->executeQuery(
            "SELECT slug, COALESCE(updated_at, created_at) AS lastmod
             FROM blog_post
             WHERE status = 'published'
             ORDER BY published_at DESC"
        )->fetchAllAssociative();

        // Catégories de blog ayant au moins un article publié
        $blogCategories = $this->db->executeQuery(
            "SELECT bc.slug, MAX(COALESCE(bp.updated_at, bp.created_at)) AS lastmod
             FROM blog_category bc
             INNER JOIN blog_post bp ON bp.category_id = bc.id
             WHERE bp.status = 'published'
             GROUP BY bc.id, bc.slug
             ORDER BY bc.name"
        )->fetchAllAssociative();

        $content = $this->renderView('sitemap/sitemap.xml.twig', [
            'baseUrl'        => $baseUrl,
            'staticPages'    => $staticPages,
            'products'       => $products,
            'categories'     => $categories,
            'blogPosts'      => $blogPosts,
            'blogCategories' => $blogCategories,
        ]);

        $response = new Response($content, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
        $response->headers->set('Cache-Control', 'public, max-age=3600');

        return $response;
    }
}
