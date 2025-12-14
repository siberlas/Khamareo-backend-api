<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Service\MailerService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

#[AsController]
class ForgotPasswordController
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private MailerService $mailerService
    ) {}

    #[Route('/api/forgot-password', name: 'forgot_password', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? null;

        // Toujours répondre la même chose pour éviter de leak les emails existants
        $genericResponse = new JsonResponse([
            'message' => 'Si un compte existe, un e-mail a été envoyé.'
        ]);

        if (!$email) {
            return new JsonResponse(['error' => 'Email requis'], 400);
        }

        $user = $this->userRepository->findOneBy(['email' => $email]);

        if (!$user) {
            return $genericResponse;
        }

        // Génération du token
        $token = Uuid::v4()->toRfc4122();
        $user->setResetPasswordToken($token);
        $user->setResetPasswordRequestedAt(new \DateTime());

        $this->em->persist($user);
        $this->em->flush();

        // Envoi email
        $this->mailerService->sendPasswordResetEmail($user, $token);

        return $genericResponse;
    }
}
