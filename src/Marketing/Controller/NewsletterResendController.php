<?php

namespace App\Marketing\Controller;
use Symfony\Component\HttpKernel\Attribute\AsController;

use App\Marketing\Repository\NewsletterSubscriberRepository;
use App\Shared\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Renvoi de l'email de confirmation newsletter (double opt-in).
 *
 * POST /api/newsletter/resend-confirm
 *   body: { "email": "user@example.com" }
 *
 * Garde-fou : un seul renvoi autorisé toutes les 10 minutes par adresse.
 */
#[AsController]
class NewsletterResendController extends AbstractController
{
    private const COOLDOWN_SECONDS = 600; // 10 minutes

    public function __construct(
        private NewsletterSubscriberRepository $repository,
        private EntityManagerInterface         $em,
        private MailerService                  $mailerService,
        private string                         $backendUrl,
        private string                         $frontBaseUrl,
    ) {}

    #[Route('/api/newsletter/resend-confirm', name: 'newsletter_resend_confirm', methods: ['POST'])]
    public function resend(Request $request): JsonResponse
    {
        $body  = $request->toArray();
        $email = strtolower(trim((string) ($body['email'] ?? '')));

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'invalid_email', 'message' => 'Adresse email invalide.'], 400);
        }

        $subscriber = $this->repository->findOneBy(['email' => $email]);

        // Réponse générique pour ne pas révéler si l'email est inscrit (anti-énumération)
        if (!$subscriber) {
            return $this->json([
                'message' => 'Si cette adresse est inscrite et non confirmée, un email vient d\'être envoyé.',
            ]);
        }

        if ($subscriber->isConfirmed()) {
            return $this->json([
                'error'   => 'already_confirmed',
                'message' => 'Cette adresse email est déjà confirmée.',
            ], 409);
        }

        // Garde-fou : cooldown de 10 minutes entre deux renvois
        $lastSent = $subscriber->getConfirmationSentAt();
        if ($lastSent !== null) {
            $elapsed = (new \DateTimeImmutable())->getTimestamp() - $lastSent->getTimestamp();
            if ($elapsed < self::COOLDOWN_SECONDS) {
                $retryAfter = self::COOLDOWN_SECONDS - $elapsed;
                $minutes    = (int) ceil($retryAfter / 60);
                return $this->json([
                    'error'      => 'rate_limited',
                    'message'    => sprintf(
                        'Veuillez patienter encore %d minute%s avant de renvoyer l\'email de confirmation.',
                        $minutes,
                        $minutes > 1 ? 's' : ''
                    ),
                    'retryAfter' => $retryAfter,
                ], 429);
            }
        }

        // Générer un nouveau token et mettre à jour l'horodatage
        $token = bin2hex(random_bytes(32));
        $subscriber->setConfirmationToken($token);
        $subscriber->setConfirmationSentAt(new \DateTimeImmutable());
        $this->em->flush();

        $confirmUrl = $this->backendUrl . '/api/newsletter/confirm?token=' . $token;
        $this->mailerService->sendNewsletterConfirmationEmail($subscriber, $confirmUrl);

        return $this->json([
            'message' => 'Email de confirmation renvoyé. Vérifiez votre boîte de réception.',
        ]);
    }
}
