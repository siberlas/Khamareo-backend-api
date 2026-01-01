<?php

namespace App\User\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use App\User\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
class ResetPasswordController
{
    #[Route('/api/reset-password', name: 'reset_password', methods: ['POST'])]
    public function resetPassword(
        Request $request,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em
    ): JsonResponse {

        $data = json_decode($request->getContent(), true);
        $token = $data['token'] ?? null;
        $newPassword = $data['password'] ?? null;

        if (!$token || !$newPassword) {
            return new JsonResponse(['error' => 'Token et nouveau mot de passe requis'], 400);
        }

        $user = $userRepository->findOneBy(['resetPasswordToken' => $token]);

        if (!$user) {
            return new JsonResponse(['error' => 'Token invalide'], 400);
        }

        $requestedAt = $user->getResetPasswordRequestedAt();
        $now = new \DateTime();

        if (!$requestedAt || $requestedAt->modify('+30 minutes') < $now) {
            return new JsonResponse(['error' => 'Le lien de réinitialisation a expiré'], 400);
        }

        // Hash du nouveau mot de passe
        $hashedPassword = $passwordHasher->hashPassword($user, $newPassword);
        $user->setPassword($hashedPassword);

        // On supprime le token
        $user->setResetPasswordToken(null);

        // Persist + flush
        $em->flush();

        return new JsonResponse(['message' => 'Mot de passe mis à jour.']);
    }
}
