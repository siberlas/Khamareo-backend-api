<?php

namespace App\EventListener;

use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class CheckVerifiedUserListener
{
    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        /** @var UserInterface $user */
        $user = $event->getUser();

        if (!$user instanceof UserInterface) {
            return;
        }

        if (method_exists($user, 'isVerified') && !$user->isVerified()) {
            $event->setResponse(new JsonResponse([
                'message' => "Votre compte n'est pas confirmé. Vérifiez vos emails."
            ], 403));
        }
    }
}
