<?php

namespace App\Shipping\Controller;

use App\Shipping\Service\ColissimoAuthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
final class ColissimoTokenController extends AbstractController
{
    #[Route('/api/public/colissimo/token', name: 'api_public_colissimo_token', methods: ['GET'])]
    public function __invoke(ColissimoAuthService $auth): JsonResponse
    {
        return $this->json([
            'token' => $auth->getToken(),
        ]);
    }
}
