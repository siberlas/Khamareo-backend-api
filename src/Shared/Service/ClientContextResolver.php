<?php

namespace App\Shared\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Résout la provenance visiteur (source de trafic, pays, OS, type d'appareil)
 * à partir d'une Request, sans dépendance externe ni fingerprinting :
 * - country  : header Cloudflare CF-IPCountry (le site est déjà derrière
 *              Cloudflare, ce header est fourni gratuitement, pas de GeoIP à installer)
 * - source   : domaine du header Referer, normalisé vers un libellé connu
 * - osName / deviceType : parsing minimal du User-Agent (regex, pas de lib UA)
 */
class ClientContextResolver
{
    private const KNOWN_SOURCES = [
        'google.'     => 'Google',
        'bing.'       => 'Bing',
        'duckduckgo.' => 'DuckDuckGo',
        'yahoo.'      => 'Yahoo',
        'instagram.'  => 'Instagram',
        'l.instagram' => 'Instagram',
        'facebook.'   => 'Facebook',
        'fb.'         => 'Facebook',
        'lm.facebook' => 'Facebook',
        'tiktok.'     => 'TikTok',
        'pinterest.'  => 'Pinterest',
        'twitter.'    => 'X (Twitter)',
        't.co'        => 'X (Twitter)',
        'x.com'       => 'X (Twitter)',
        'youtube.'    => 'YouTube',
        'linkedin.'   => 'LinkedIn',
        'whatsapp.'   => 'WhatsApp',
        'mail.'       => 'Email',
        'gmail.'      => 'Email',
        'outlook.'    => 'Email',
    ];

    public function resolveCountry(Request $request): ?string
    {
        $cfCountry = $request->headers->get('CF-IPCountry');
        if ($cfCountry && preg_match('/^[A-Z]{2}$/', $cfCountry) && $cfCountry !== 'XX') {
            return $cfCountry;
        }

        return null;
    }

    public function resolveSource(?string $referrer): ?string
    {
        if (!$referrer) {
            return 'Direct';
        }

        $host = parse_url($referrer, PHP_URL_HOST);
        if (!$host) {
            return 'Direct';
        }
        $host = strtolower($host);

        foreach (self::KNOWN_SOURCES as $needle => $label) {
            if (str_contains($host, $needle)) {
                return $label;
            }
        }

        // Domaine externe non reconnu : garder le nom de domaine tel quel (utile en site B2B/blog partenaires)
        return preg_replace('/^www\./', '', $host);
    }

    public function resolveOsName(Request $request): ?string
    {
        $ua = $request->headers->get('User-Agent', '');
        if (!$ua) {
            return null;
        }

        return match (true) {
            (bool) preg_match('/iPhone|iPad|iPod/i', $ua)       => 'iOS',
            (bool) preg_match('/Android/i', $ua)                => 'Android',
            (bool) preg_match('/Windows Phone/i', $ua)          => 'Windows Phone',
            (bool) preg_match('/Windows/i', $ua)                => 'Windows',
            (bool) preg_match('/Macintosh|Mac OS X/i', $ua)     => 'macOS',
            (bool) preg_match('/CrOS/i', $ua)                   => 'ChromeOS',
            (bool) preg_match('/Linux/i', $ua)                  => 'Linux',
            default                                              => 'Autre',
        };
    }

    public function resolveDeviceType(Request $request): ?string
    {
        $ua = $request->headers->get('User-Agent', '');
        if (!$ua) {
            return null;
        }

        if (preg_match('/iPad|Android(?!.*Mobile)|Tablet|Kindle|PlayBook/i', $ua)) {
            return 'tablet';
        }

        if (preg_match('/iPhone|iPod|Android.*Mobile|Windows Phone|Mobile Safari/i', $ua)) {
            return 'mobile';
        }

        if (preg_match('/bot|crawl|spider|slurp|facebookexternalhit/i', $ua)) {
            return 'bot';
        }

        return 'desktop';
    }

    /**
     * @return array{source: ?string, country: ?string, osName: ?string, deviceType: ?string}
     */
    public function resolve(Request $request, ?string $referrer = null): array
    {
        return [
            'source'     => $this->resolveSource($referrer ?? $request->headers->get('Referer')),
            'country'    => $this->resolveCountry($request),
            'osName'     => $this->resolveOsName($request),
            'deviceType' => $this->resolveDeviceType($request),
        ];
    }
}
