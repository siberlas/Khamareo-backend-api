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

        if (!$requestedAt || $requestedAt->modify('+1 hour') < $now) {
            return new JsonResponse(['error' => 'Le lien de réinitialisation a expiré'], 400);
        }

        // Hash du nouveau mot de passe
        $hashedPassword = $passwordHasher->hashPassword($user, $newPassword);
        $user->setPassword($hashedPassword);

        // Un invité qui réinitialise son mot de passe prouve la propriété de son
        // email (lien reçu par mail) : on le promeut en compte réel, sinon il reste
        // marqué isGuest=true pour toujours et échappe au check anti-doublon du
        // checkout invité (GuestCartAddressProcessor::isGuest()).
        if ($user->isGuest()) {
            $user
                ->setIsGuest(false)
                ->setIsVerified(true)
                ->setGuestExpiresAt(null);
        }

        // On supprime le token
        $user->setResetPasswordToken(null);

        // Persist + flush
        $em->flush();

        return new JsonResponse(['message' => 'Mot de passe mis à jour.']);
    }
}
