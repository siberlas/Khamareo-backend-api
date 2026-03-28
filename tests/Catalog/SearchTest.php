<?php

namespace App\Tests\Catalog;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests for the search suggestions endpoint.
 * Covers: public access, search results structure, empty search.
 */
class SearchTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    // -----------------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------------

    /** GET /api/search/suggestions is public (no auth needed) */
    public function testSearchSuggestionsIsPublic(): void
    {
        $this->client->request('GET', '/api/search/suggestions');

        $status = $this->client->getResponse()->getStatusCode();
        $this->assertNotSame(401, $status, 'Search suggestions should not require auth');
        $this->assertNotSame(403, $status, 'Search suggestions should not be forbidden');
        $this->assertResponseIsSuccessful();
    }

    /** GET /api/search/suggestions returns products, categories, posts keys */
    public function testSearchReturnsStructuredResults(): void
    {
        $this->client->request('GET', '/api/search/suggestions?q=test');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('products', $data);
        $this->assertArrayHasKey('categories', $data);
        $this->assertArrayHasKey('posts', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertIsArray($data['products']);
        $this->assertIsArray($data['categories']);
        $this->assertIsArray($data['posts']);
        $this->assertIsInt($data['total']);
    }

    /** Empty search (q too short) returns featured content */
    public function testEmptySearchReturnsFeaturedContent(): void
    {
        $this->client->request('GET', '/api/search/suggestions?q=');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('products', $data);
        $this->assertArrayHasKey('categories', $data);
        $this->assertArrayHasKey('posts', $data);
        $this->assertArrayHasKey('total', $data);
        // With empty query, categories should include all enabled ones
        $this->assertIsArray($data['categories']);
    }

    /** Search with a single character (too short) also returns featured */
    public function testSingleCharSearchReturnsFeatured(): void
    {
        $this->client->request('GET', '/api/search/suggestions?q=a');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('products', $data);
        $this->assertArrayHasKey('categories', $data);
    }
}
