<?php

namespace App\Marketing\Controller;
use Symfony\Component\HttpKernel\Attribute\AsController;

use App\Marketing\Repository\NewsletterSubscriberRepository;
use App\Marketing\Service\PromoCodeService;
use App\Shared\Repository\AppSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoint public pour la confirmation du double opt-in newsletter.
 *
 * GET /api/newsletter/confirm?token=<hex64>
 *  → valide l'abonnement, envoie le code promo, redirige vers le frontend
 */
#[AsController]
class NewsletterConfirmController extends AbstractController
{
    public function __construct(
        private NewsletterSubscriberRepository $repository,
        private EntityManagerInterface         $em,
        private PromoCodeService               $promoCodeService,
        private AppSettingsRepository          $settingsRepo,
        private string                         $frontBaseUrl,
    ) {}

    #[Route('/api/newsletter/confirm', name: 'newsletter_confirm', methods: ['GET'])]
    public function confirm(Request $request): Response
    {
        $token = $request->query->get('token', '');

        if (!$token) {
            return new RedirectResponse($this->frontBaseUrl . '/newsletter/error?reason=missing_token');
        }

        $subscriber = $this->repository->findByConfirmationToken($token);

        if (!$subscriber) {
            return new RedirectResponse($this->frontBaseUrl . '/newsletter/error?reason=invalid_token');
        }

        if ($subscriber->isConfirmed()) {
            return new RedirectResponse($this->frontBaseUrl . '/newsletter/confirmed?already=1');
        }

        // Confirmer l'abonnement
        $subscriber->setConfirmedAt(new \DateTimeImmutable());
        $subscriber->setConfirmationToken(null);
        $this->em->flush();

        // Envoyer le code promo uniquement si le site est ouvert.
        // Pendant le coming soon, le promo est inclus dans le batch de lancement.
        $comingSoon = $this->settingsRepo->findByKey('coming_soon_enabled');
        $promoSent = false;

        if ($comingSoon?->getSettingValue() !== 'true') {
            try {
                $result = $this->promoCodeService->handleNewsletterSubscription($subscriber->getEmail());
                $promoSent = ($result !== null);
            } catch (\Exception) {
                // Ne pas bloquer la confirmation si le promo échoue
            }
        }

        $suffix = $promoSent ? '' : '?promo=0';
        return new RedirectResponse($this->frontBaseUrl . '/newsletter/confirmed' . $suffix);
    }
}
