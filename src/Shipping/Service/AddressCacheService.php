<?php

namespace App\Shipping\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;

class AddressCacheService
{
    public function __construct(
        private AdapterInterface $cache,
        private LoggerInterface $logger,
    ) {}

    /**
     * Generate cache key for address autocomplete
     */
    private function getCacheKey(string $source, string $query, ?string $country = null): string
    {
        $key = sprintf('address_%s_%s_%s', $source, md5($query), $country ?? 'all');
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
    }

    /**
     * Get cached results if available
     */
    public function get(string $source, string $query, ?string $country = null): ?array
    {
        $key = $this->getCacheKey($source, $query, $country);
        $item = $this->cache->getItem($key);

        if ($item->isHit()) {
            $this->logger->debug('Cache hit for address', ['key' => $key, 'source' => $source]);
            return $item->get();
        }

        return null;
    }

    /**
     * Cache results for 1 hour
     */
    public function set(string $source, string $query, ?string $country, array $results): void
    {
        $key = $this->getCacheKey($source, $query, $country);
        $item = $this->cache->getItem($key);
        $item->set($results);
        $item->expiresAfter(3600); // 1 hour
        $this->cache->save($item);
        $this->logger->debug('Cached address results', ['key' => $key, 'count' => count($results)]);
    }
}
