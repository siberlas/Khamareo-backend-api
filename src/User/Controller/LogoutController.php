<?php

namespace App\User\Controller;

use App\Entity\RefreshToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
class LogoutController
{
    #[Route('/api/logout', name: 'api_logout', methods: ['POST'])]
    public function __invoke(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $refreshToken = $request->cookies->get('refresh_token')
            ?? $request->request->get('refresh_token');

        if ($refreshToken) {
            $tokenEntity = $em->getRepository(RefreshToken::class)
                ->findOneBy(['refreshToken' => $refreshToken]);

            if ($tokenEntity) {
                $em->remove($tokenEntity);
                $em->flush();
            }
        }

        $cookie = Cookie::create('refresh_token')
            ->withValue('')
            ->withExpires(new \DateTimeImmutable('-1 hour'))
            ->withPath('/')
            ->withHttpOnly(true)
            ->withSecure($request->isSecure())
            ->withSameSite($request->isSecure() ? 'none' : 'lax');

        $response = new JsonResponse(['success' => true]);
        $response->headers->setCookie($cookie);

        return $response;
    }
}