<?php

namespace App\Marketing\Controller;
use Symfony\Component\HttpKernel\Attribute\AsController;

use App\Marketing\Repository\NewsletterSubscriberRepository;
use App\Shared\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Renvoi de l'email de confirmation newsletter (double opt-in).
 *
 * POST /api/newsletter/resend-confirm
 *   body: { "email": "user@example.com" }
 */
#[AsController]
class NewsletterResendController extends AbstractController
{
    public function __construct(
        private NewsletterSubscriberRepository $repository,
        private EntityManagerInterface         $em,
        private MailerService                  $mailerService,
        private RateLimiterFactory             $newsletterLimiter,
        private string                         $backendUrl,
    ) {}

    #[Route('/api/newsletter/resend-confirm', name: 'newsletter_resend_confirm', methods: ['POST'])]
    public function resend(Request $request): JsonResponse
    {
        $limiter = $this->newsletterLimiter->create($request->getClientIp() ?? 'unknown');
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json([
                'error'   => 'rate_limited',
                'message' => 'Trop de tentatives. Veuillez patienter 15 minutes avant de réessayer.',
            ], 429);
        }

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

        // Générer un nouveau token et mettre à jour l'horodatage
        $token = bin2hex(random_bytes(32));
        $subscriber->setConfirmationToken($token);
        $subscriber->setConfirmationSentAt(new \DateTimeImmutable());
        $this->em->flush();

        $confirmUrl     = $this->backendUrl . '/api/newsletter/confirm?token=' . $token;
        $unsubscribeUrl = $this->backendUrl . '/api/newsletter/unsubscribe?token=' . $subscriber->getUnsubscribeToken();
        $this->mailerService->sendNewsletterConfirmationEmail($subscriber, $confirmUrl, $unsubscribeUrl);

        return $this->json([
            'message' => 'Email de confirmation renvoyé. Vérifiez votre boîte de réception.',
        ]);
    }
}
