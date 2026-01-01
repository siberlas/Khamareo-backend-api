<?php

namespace App\User\Controller;

use App\User\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
class ConfirmAccountController
{
    #[Route('/api/confirm/{token}', name: 'confirm_account', methods: ['GET'])]
    public function __invoke(
        UserRepository $userRepository,
        EntityManagerInterface $em,
        string $token
    ): Response
    {
        $user = $userRepository->findOneBy(['confirmationToken' => $token]);

        if (!$user) {
            return new Response("Lien invalide ou expiré.", 400);
        }

        $user->setIsVerified(true);
        $user->setConfirmationToken(null);

        // Pas de persist → l’entité est déjà managée
        $em->flush();

        // Redirection vers le front (simple, sans Twig)
         $frontendUrl = $_ENV['FRONTEND_BASE_URL'] ?? 'http://localhost:8081';

        return new Response("
            <script>
                window.location.href = '$frontendUrl/login?confirmed=true';
            </script>
        ");
    }
}
