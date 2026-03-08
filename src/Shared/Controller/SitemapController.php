<?php

namespace App\Shared\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
class SitemapController extends AbstractController
{
    public function __construct(private Connection $db) {}

    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'])]
    public function __invoke(): Response
    {
        $baseUrl = 'https://khamareo.com';

        $urls = [];

        // Static pages
        $staticPages = [
            ['loc' => '/', 'priority' => '1.0', 'changefreq' => 'daily'],
            ['loc' => '/boutique', 'priority' => '0.9', 'changefreq' => 'daily'],
            ['loc' => '/blog', 'priority' => '0.8', 'changefreq' => 'daily'],
            ['loc' => '/about', 'priority' => '0.6', 'changefreq' => 'monthly'],
            ['loc' => '/contact', 'priority' => '0.5', 'changefreq' => 'monthly'],
            ['loc' => '/terms', 'priority' => '0.3', 'changefreq' => 'yearly'],
            ['loc' => '/privacy', 'priority' => '0.3', 'changefreq' => 'yearly'],
            ['loc' => '/retractation', 'priority' => '0.3', 'changefreq' => 'yearly'],
        ];
        foreach ($staticPages as $page) {
            $urls[] = $page;
        }

        // Products (enabled, not deleted)
        $products = $this->db->executeQuery(
            "SELECT slug, updated_at FROM product WHERE is_enabled = true AND is_deleted = false ORDER BY updated_at DESC"
        )->fetchAllAssociative();

        foreach ($products as $row) {
            $urls[] = [
                'loc' => '/product/' . $row['slug'],
                'lastmod' => substr($row['updated_at'], 0, 10),
                'priority' => '0.8',
                'changefreq' => 'weekly',
            ];
        }

        // Categories (enabled)
        $categories = $this->db->executeQuery(
            "SELECT slug FROM category WHERE is_enabled = true ORDER BY display_order"
        )->fetchAllAssociative();

        foreach ($categories as $row) {
            $urls[] = [
                'loc' => '/boutique/' . $row['slug'],
                'priority' => '0.7',
                'changefreq' => 'weekly',
            ];
        }

        // Blog posts (published)
        $posts = $this->db->executeQuery(
            "SELECT slug, updated_at FROM blog_post WHERE status = 'published' ORDER BY published_at DESC"
        )->fetchAllAssociative();

        foreach ($posts as $row) {
            $urls[] = [
                'loc' => '/blog/' . $row['slug'],
                'lastmod' => substr($row['updated_at'], 0, 10),
                'priority' => '0.7',
                'changefreq' => 'monthly',
            ];
        }

        // Build XML
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $url) {
            $xml .= "  <url>\n";
            $xml .= "    <loc>{$baseUrl}{$url['loc']}</loc>\n";
            if (isset($url['lastmod'])) {
                $xml .= "    <lastmod>{$url['lastmod']}</lastmod>\n";
            }
            $xml .= "    <changefreq>{$url['changefreq']}</changefreq>\n";
            $xml .= "    <priority>{$url['priority']}</priority>\n";
            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>';

        $response = new Response($xml, 200, ['Content-Type' => 'application/xml']);
        $response->headers->set('Cache-Control', 'public, max-age=3600');

        return $response;
    }
}
