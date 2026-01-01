<?php
// src/Controller/Auth/ResendConfirmationController.php

namespace App\User\Controller;

use App\User\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use App\Shared\Service\MailerService;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
class ResendConfirmationController
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private MailerInterface $mailer,
        private ParameterBagInterface $params, // pour FRONTEND_URL
        private MailerService $mailerService
    ) {}

    #[Route('api/resend-confirmation', name: 'resend_confirmation', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $email = $payload['email'] ?? null;

        if (!$email) {
            return new JsonResponse(['error' => 'Email manquant'], 400);
        }

        $user = $this->users->findOneBy(['email' => $email]);
        if (!$user) {
            // évite de “leaker” l’existence d’un compte : réponds 200
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

        $frontend = rtrim((string)$this->params->get('app.frontend_base_url'), '/');
        $link = $frontend.'/confirm/'.$user->getConfirmationToken();

        $this->mailerService->sendEmailConfirmation($user, $user->getConfirmationToken());
       
        return new JsonResponse(['status' => 'ok']);
    }
}
