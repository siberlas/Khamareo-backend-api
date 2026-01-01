<?php
// src/Controller/TestMediaController.php

namespace App\Media\Controller;

use App\Media\Service\MediaService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/test')]
#[AsController]
class TestMediaController extends AbstractController
{
    #[Route('/media/stats', methods: ['GET'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function stats(MediaService $mediaService): JsonResponse
    {
        return $this->json($mediaService->getStats());
    }
}