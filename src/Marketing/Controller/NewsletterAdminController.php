<?php

namespace App\Marketing\Controller;
use Symfony\Component\HttpKernel\Attribute\AsController;

use App\Marketing\Repository\NewsletterSubscriberRepository;
use App\Shared\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Actions admin sur les abonnés newsletter.
 *
 * POST /api/admin/newsletter/resend/{id}
 *   Renvoie l'email de confirmation pour un abonné non confirmé (sans rate-limit côté admin).
 */
#[IsGranted('ROLE_ADMIN')]
#[AsController]
class NewsletterAdminController extends AbstractController
{
    public function __construct(
        private NewsletterSubscriberRepository $repository,
        private EntityManagerInterface         $em,
        private MailerService                  $mailerService,
        private string                         $backendUrl,
    ) {}

    #[Route('/api/admin/newsletter/resend/{id}', name: 'admin_newsletter_resend', methods: ['POST'])]
    public function resend(string $id): JsonResponse
    {
        $subscriber = $this->repository->find($id);

        if (!$subscriber) {
            return $this->json(['error' => 'not_found', 'message' => 'Abonné introuvable.'], 404);
        }

        if ($subscriber->isConfirmed()) {
            return $this->json([
                'error'   => 'already_confirmed',
                'message' => 'Cet abonné est déjà confirmé.',
            ], 409);
        }

        // Générer un nouveau token et mettre à jour l'horodatage (pas de cooldown admin)
        $token = bin2hex(random_bytes(32));
        $subscriber->setConfirmationToken($token);
        $subscriber->setConfirmationSentAt(new \DateTimeImmutable());
        $this->em->flush();

        $confirmUrl = $this->backendUrl . '/api/newsletter/confirm?token=' . $token;
        $this->mailerService->sendNewsletterConfirmationEmail($subscriber, $confirmUrl);

        return $this->json([
            'message' => 'Email de confirmation renvoyé à ' . $subscriber->getEmail(),
        ]);
    }
}
