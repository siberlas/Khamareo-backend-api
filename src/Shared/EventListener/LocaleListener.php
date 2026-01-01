<?php

namespace App\Shared\EventListener;

use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Psr\Log\LoggerInterface;

class LocaleListener implements EventSubscriberInterface
{
    public function __construct(
        private string $defaultLocale = 'fr',
        private ?LoggerInterface $logger = null
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        
        $this->logger?->info('🌍 LocaleListener - Processing request', [
            'uri' => $request->getRequestUri(),
            'accept_language' => $request->headers->get('Accept-Language'),
        ]);
        
        // 1. Vérifier si la locale est dans l'URL (/fr/, /en/)
        if ($locale = $request->attributes->get('_locale')) {
            $request->setLocale($locale);
            $this->logger?->info('🌍 LocaleListener - Locale from URL', ['locale' => $locale]);
            return;
        }

        // 2. Parser le header Accept-Language
        if ($acceptLanguage = $request->headers->get('Accept-Language')) {
            $locale = $this->parseAcceptLanguage($acceptLanguage);
            
            $this->logger?->info('🌍 LocaleListener - Accept-Language header', [
                'raw' => $acceptLanguage,
                'parsed' => $locale, // ← ATTENTION : "parsed" et non "extracted"
            ]);
            
            if ($locale) {
                $request->setLocale($locale);
                $this->logger?->info('🌍 LocaleListener - Locale set from header', ['locale' => $locale]);
                return;
            }
        }

        // 3. Query param ?lang=en
        if ($locale = $request->query->get('lang')) {
            if (in_array($locale, ['fr', 'en'])) {
                $request->setLocale($locale);
                $this->logger?->info('🌍 LocaleListener - Locale from query param', ['locale' => $locale]);
                return;
            }
        }

        // 4. Utiliser la locale par défaut
        $request->setLocale($this->defaultLocale);
        $this->logger?->info('🌍 LocaleListener - Using default locale', ['locale' => $this->defaultLocale]);
    }

    /**
     * Parse le header Accept-Language et retourne la première locale supportée
     * 
     * Exemples:
     * - "en" → "en"
     * - "en-US" → "en"
     * - "en-US,en;q=0.9,fr;q=0.8" → "en"
     * - "fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7" → "fr"
     */
    private function parseAcceptLanguage(string $acceptLanguage): ?string
    {
        // Séparer les langues par virgule
        $languages = explode(',', $acceptLanguage);
        
        foreach ($languages as $language) {
            // Supprimer les paramètres de qualité (;q=0.9)
            $language = explode(';', $language)[0];
            
            // Nettoyer les espaces
            $language = trim($language);
            
            // Extraire les 2 premiers caractères (en-US → en, fr-FR → fr)
            $locale = strtolower(substr($language, 0, 2));
            
            // Vérifier si c'est une locale supportée
            if (in_array($locale, ['fr', 'en'])) {
                return $locale;
            }
        }
        
        return null;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 20]],
        ];
    }
}