<?php

namespace App\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Event\LogoutEvent;

#[AsEventListener(event: LogoutEvent::class)]
class AdminLogoutListener
{
    public function __invoke(LogoutEvent $event): void
    {
        if (str_starts_with($event->getRequest()->getPathInfo(), '/waraba-l19')) {
            $event->setResponse(new JsonResponse(['message' => 'Déconnexion réussie']));
        }
    }
}
