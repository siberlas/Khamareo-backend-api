<?php

namespace App\Marketing\Controller;
use Symfony\Component\HttpKernel\Attribute\AsController;

use App\Marketing\Repository\NewsletterSubscriberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoint public de désabonnement newsletter (CNIL obligatoire).
 *
 * GET /api/newsletter/unsubscribe?token=<hex64>
 *  → supprime l'abonné et redirige vers une page de confirmation frontend
 */
#[AsController]
class NewsletterUnsubscribeController extends AbstractController
{
    public function __construct(
        private NewsletterSubscriberRepository $repository,
        private EntityManagerInterface         $em,
        private string                         $frontBaseUrl,
    ) {}

    #[Route('/api/newsletter/unsubscribe', name: 'newsletter_unsubscribe', methods: ['GET'])]
    public function unsubscribe(Request $request): Response
    {
        $token = $request->query->get('token', '');

        if (!$token) {
            return new RedirectResponse($this->frontBaseUrl . '/newsletter/error?reason=missing_token');
        }

        $subscriber = $this->repository->findByUnsubscribeToken($token);

        if (!$subscriber) {
            // Déjà désabonné ou token invalide — on redirige quand même vers succès
            return new RedirectResponse($this->frontBaseUrl . '/newsletter/unsubscribed');
        }

        $this->em->remove($subscriber);
        $this->em->flush();

        return new RedirectResponse($this->frontBaseUrl . '/newsletter/unsubscribed');
    }
}
