<?php

namespace App\Shipping\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ColissimoAuthService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly string $colissimoApiKey,
        private readonly int $tokenTtlSeconds,
        private readonly string $authApiUrl
    ) {}

    /**
     * Retourne un token Colissimo (cache).
     */
    public function getToken(): string
    {
        return $this->cache->get('colissimo.widget.token', function (ItemInterface $item) {
            $item->expiresAfter($this->tokenTtlSeconds);

            $response = $this->httpClient->request('POST', $this->authApiUrl, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'apikey' => $this->colissimoApiKey,
                ],
                // évite les erreurs “timeout” trop agressives
                'timeout' => 15,
            ]);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                // force debug si ça arrive
                $body = $response->getContent(false);
                throw new \RuntimeException("Colissimo authenticate failed: HTTP $this->authApiUrl $this->colissimoApiKey");
            }

            $data = $response->toArray(false);

            if (!is_array($data) || empty($data['token']) || !is_string($data['token'])) {
                throw new \RuntimeException('Colissimo authenticate: invalid JSON response (missing token).');
            }

            return $data['token'];
        });
    }
}
