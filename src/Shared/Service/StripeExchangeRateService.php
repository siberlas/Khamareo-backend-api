<?php

namespace App\Shared\Service;

use Predis\Client as RedisClient;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class StripeExchangeRateService
{
    private const CACHE_KEY = 'stripe_fx_rates';
    private const CACHE_TTL = 300; // 5 minutes
    private const STRIPE_VERSION = '2025-03-31.preview';
    private const SUPPORTED_CURRENCIES = ['cad', 'usd', 'gbp', 'chf'];

    private RedisClient $redis;

    public function __construct(
        private readonly string            $stripeSecretKey,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface   $logger,
    ) {
        $this->redis = new RedisClient(['host' => 'redis', 'port' => 6379, 'timeout' => 1.0]);
    }

    /**
     * Retourne les taux de conversion depuis EUR.
     * Exemple : ['EUR' => 1.0, 'CAD' => 1.49, 'USD' => 1.09, 'GBP' => 0.85, 'CHF' => 0.93]
     * Mis en cache Redis 5 min.
     */
    public function getRates(): array
    {
        try {
            $cached = $this->redis->get(self::CACHE_KEY);
            if ($cached !== null) {
                return json_decode($cached, true);
            }
        } catch (\Throwable) {
            // Redis indisponible → on continue sans cache
        }

        $rates = $this->fetchFromStripe();

        try {
            $this->redis->setex(self::CACHE_KEY, self::CACHE_TTL, json_encode($rates));
        } catch (\Throwable) {}

        return $rates;
    }

    private function fetchFromStripe(): array
    {
        // Un seul appel : to_currency=eur, from_currencies[]=cad&...
        // exchange_rate retourné = EUR/LOCAL (ex: 0.67 EUR pour 1 CAD)
        // On inverse pour obtenir LOCAL/EUR (ex: 1.49 CAD pour 1 EUR)
        $body = 'to_currency=eur&lock_duration=none';
        foreach (self::SUPPORTED_CURRENCIES as $currency) {
            $body .= '&from_currencies[]=' . $currency;
        }

        try {
            $response = $this->httpClient->request('POST', 'https://api.stripe.com/v1/fx_quotes', [
                'auth_basic' => [$this->stripeSecretKey, ''],
                'headers' => [
                    'Stripe-Version' => self::STRIPE_VERSION,
                    'Content-Type'   => 'application/x-www-form-urlencoded',
                ],
                'body' => $body,
            ]);

            $data = $response->toArray();
            $rates = ['EUR' => 1.0];

            foreach ($data['rates'] ?? [] as $fromCurrency => $rateData) {
                // base_rate = EUR/LOCAL sans les frais Stripe (on affiche seulement, on ne débite pas en devise locale)
                $eurPerLocal = (float) ($rateData['rate_details']['base_rate'] ?? $rateData['exchange_rate'] ?? 0);
                if ($eurPerLocal > 0) {
                    // base_rate = EUR/LOCAL → on veut LOCAL/EUR = 1 / base_rate
                    $rates[strtoupper($fromCurrency)] = round(1.0 / $eurPerLocal, 6);
                }
            }

            $this->logger->info('Stripe FX Quotes récupérés', ['rates' => $rates]);

            return $rates;
        } catch (\Throwable $e) {
            $this->logger->warning('Stripe FX Quotes indisponible, taux de secours utilisés', [
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackRates();
        }
    }

    private function fallbackRates(): array
    {
        return [
            'EUR' => 1.0,
            'USD' => 1.10,
            'GBP' => 0.85,
            'CAD' => 1.50,
            'CHF' => 0.95,
        ];
    }
}
