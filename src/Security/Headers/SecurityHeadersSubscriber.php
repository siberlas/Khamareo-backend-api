<?php

namespace App\Security\Headers;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', 0]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        // CSP souple pour la documentation Swagger (HTML), strict pour les endpoints API (JSON)
        $path = $request->getPathInfo();
        if (str_starts_with($path, '/api/docs') || str_starts_with($path, '/api/.well-known')) {
            $headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none';");
        } else {
            $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none';");
        }

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }
    }
}