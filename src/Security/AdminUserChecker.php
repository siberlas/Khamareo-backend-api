<?php

namespace App\Security;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class AdminUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void {}

    public function checkPostAuth(UserInterface $user): void
    {
        if (!in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            throw new CustomUserMessageAuthenticationException(
                'Accès réservé aux administrateurs.'
            );
        }
    }
}
