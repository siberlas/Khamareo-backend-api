<?php

namespace App\Security;

use App\User\Entity\User;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

class UserVerifiedChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        // Les comptes admin ne peuvent pas se connecter via /api/auth (JWT client)
        // Ils doivent utiliser le portail dédié (URL opaque)
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            throw new CustomUserMessageAuthenticationException(
                'Identifiants incorrects.'
            );
        }

        if (!$user->isVerified()) {
            throw new CustomUserMessageAuthenticationException(
                'Votre compte n\'est pas encore vérifié. Vérifiez votre email.'
            );
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
        // no-op
    }
}
