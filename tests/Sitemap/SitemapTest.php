<?php

namespace App\Tests\Sitemap;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests fonctionnels pour la route /sitemap.xml.
 */
class SitemapTest extends WebTestCase
{
    private const DRAFT_SLUG     = 'article-test-draft-sitemap';
    private const PUBLISHED_SLUG = 'article-test-published-sitemap';

    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);

        // Nettoyer d'éventuels résidus de tests précédents
        $db->executeStatement(
            "DELETE FROM blog_post WHERE slug IN (?, ?)",
            [self::DRAFT_SLUG, self::PUBLISHED_SLUG]
        );

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Article brouillon — NE DOIT PAS apparaître dans le sitemap
        $db->executeStatement(
            "INSERT INTO blog_post (id, title, slug, content, status, is_featured, created_at, updated_at)
             VALUES (gen_random_uuid(), 'Draft article', ?, 'contenu', 'draft', false, ?, ?)",
            [self::DRAFT_SLUG, $now, $now]
        );

        // Article publié — DOIT apparaître dans le sitemap
        $db->executeStatement(
            "INSERT INTO blog_post (id, title, slug, content, status, is_featured, published_at, created_at, updated_at)
             VALUES (gen_random_uuid(), 'Published article', ?, 'contenu', 'published', false, ?, ?, ?)",
            [self::PUBLISHED_SLUG, $now, $now, $now]
        );
    }

    protected function tearDown(): void
    {
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement(
            "DELETE FROM blog_post WHERE slug IN (?, ?)",
            [self::DRAFT_SLUG, self::PUBLISHED_SLUG]
        );

        parent::tearDown();
    }

    public function testSitemapReturns200(): void
    {
        $this->client->request('GET', '/sitemap.xml');

        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(200);
    }

    public function testSitemapContentType(): void
    {
        $this->client->request('GET', '/sitemap.xml');

        $this->assertResponseHeaderSame('content-type', 'application/xml; charset=UTF-8');
    }

    public function testSitemapIsValidXml(): void
    {
        $this->client->request('GET', '/sitemap.xml');

        $content = $this->client->getResponse()->getContent();
        $this->assertNotEmpty($content);

        $doc = new \DOMDocument();
        $loaded = @$doc->loadXML($content);
        $this->assertTrue($loaded, 'Le sitemap doit être du XML valide et bien formé');

        // Vérification du namespace sitemaps.org
        $urlset = $doc->getElementsByTagName('urlset')->item(0);
        $this->assertNotNull($urlset, 'Le sitemap doit contenir un élément <urlset>');
        $this->assertSame(
            'http://www.sitemaps.org/schemas/sitemap/0.9',
            $urlset->getAttribute('xmlns')
        );
    }

    public function testSitemapContainsPublishedPost(): void
    {
        $this->client->request('GET', '/sitemap.xml');

        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('/blog/' . self::PUBLISHED_SLUG, $content);
    }

    public function testSitemapExcludesDraftPost(): void
    {
        $this->client->request('GET', '/sitemap.xml');

        $content = $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('/blog/' . self::DRAFT_SLUG, $content);
    }

    public function testSitemapContainsStaticPages(): void
    {
        $this->client->request('GET', '/sitemap.xml');

        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('<loc>', $content);
        // Au moins les pages statiques principales
        foreach (['/', '/boutique', '/blog'] as $path) {
            $this->assertStringContainsString($path, $content);
        }
    }
}
