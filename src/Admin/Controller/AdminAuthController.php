<?php

namespace App\Admin\Controller;

use App\User\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
class AdminAuthController extends AbstractController
{
    public function __construct(
        private readonly JWTTokenManagerInterface $jwtManager,
    ) {}

    /**
     * Cette route est interceptée par le firewall json_login.
     * Elle ne sera jamais exécutée directement.
     */
    #[Route('/waraba-l19/login', name: 'admin_login', methods: ['POST'])]
    public function login(): void
    {
        throw new \LogicException('Cette route est gérée par le firewall Symfony.');
    }

    /**
     * Cette route est interceptée par le firewall logout.
     * Elle ne sera jamais exécutée directement.
     */
    #[Route('/waraba-l19/logout', name: 'admin_logout', methods: ['POST'])]
    public function logout(): void
    {
        throw new \LogicException('Cette route est gérée par le firewall Symfony.');
    }

    /**
     * Récupère la session admin active et retourne un JWT frais.
     * Utilisé par le frontend pour restaurer la session après rechargement.
     */
    #[Route('/waraba-l19/me', name: 'admin_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'id'        => (string) $user->getId(),
            'email'     => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName'  => $user->getLastName(),
            'roles'     => $user->getRoles(),
            'token'     => $this->jwtManager->create($user),
        ]);
    }
}
