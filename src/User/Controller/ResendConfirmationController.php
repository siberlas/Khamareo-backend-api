<?php
// src/Controller/Auth/ResendConfirmationController.php

namespace App\User\Controller;

use App\User\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;
use App\Shared\Service\MailerService;

#[AsController]
class ResendConfirmationController
{
    public function __construct(
        private UserRepository         $users,
        private EntityManagerInterface $em,
        private MailerService          $mailerService,
        private RateLimiterFactory     $resendConfirmationLimiter,
    ) {}

    #[Route('api/resend-confirmation', name: 'resend_confirmation', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $limiter = $this->resendConfirmationLimiter->create($request->getClientIp() ?? 'unknown');
        if (!$limiter->consume(1)->isAccepted()) {
            return new JsonResponse([
                'error'   => 'rate_limited',
                'message' => 'Trop de tentatives. Veuillez patienter 15 minutes avant de réessayer.',
            ], 429);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $email = $payload['email'] ?? null;

        if (!$email) {
            return new JsonResponse(['error' => 'Email manquant'], 400);
        }

        $user = $this->users->findOneBy(['email' => $email]);
        if (!$user) {
            // évite de "leaker" l'existence d'un compte : réponds 200
            return new JsonResponse(['status' => 'ok']);
        }

        if ($user->isVerified()) {
            // déjà confirmé → rien à renvoyer
            return new JsonResponse(['status' => 'already_verified']);
        }

        if (!$user->getConfirmationToken()) {
            $user->setConfirmationToken(Uuid::v4()->toRfc4122());
            $this->em->flush();
        }

        $this->mailerService->sendEmailConfirmation($user, $user->getConfirmationToken());

        return new JsonResponse(['status' => 'ok']);
    }
}
