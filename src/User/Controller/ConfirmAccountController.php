<?php

namespace App\User\Controller;

use App\Marketing\Entity\NewsletterSubscriber;
use App\Marketing\Repository\NewsletterSubscriberRepository;
use App\Marketing\Service\PromoCodeService;
use App\User\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
class ConfirmAccountController
{
    public function __construct(
        private UserRepository                 $userRepository,
        private EntityManagerInterface         $em,
        private PromoCodeService               $promoCodeService,
        private NewsletterSubscriberRepository $newsletterRepository,
        private ParameterBagInterface          $params,
    ) {}

    #[Route('/api/confirm/{token}', name: 'confirm_account', methods: ['GET'])]
    public function __invoke(string $token): Response
    {
        $user = $this->userRepository->findOneBy(['confirmationToken' => $token]);

        if (!$user) {
            return new Response("Lien invalide ou expiré.", 400);
        }

        $user->setIsVerified(true);
        $user->setConfirmationToken(null);
        $this->em->flush();

        $email = $user->getEmail();

        // Envoyer le code promo d'inscription (une seule fois)
        try {
            $this->promoCodeService->handleUserRegistration($email);
        } catch (\Exception) {
            // Ne pas bloquer la confirmation si le promo échoue
        }

        // Si l'utilisateur s'est inscrit à la newsletter lors de l'inscription
        if ($user->isNewsletter()) {
            $subscriber = $this->newsletterRepository->findOneBy(['email' => $email]);

            if (!$subscriber) {
                $subscriber = new NewsletterSubscriber();
                $subscriber->setEmail($email);
                $subscriber->setUnsubscribeToken(bin2hex(random_bytes(32)));
                $this->em->persist($subscriber);
            }

            if (!$subscriber->isConfirmed()) {
                $subscriber->setConfirmedAt(new \DateTimeImmutable());
                $subscriber->setConfirmationToken(null);
            }

            $this->em->flush();

            // Envoyer le code promo newsletter (une seule fois)
            try {
                $this->promoCodeService->handleNewsletterSubscription($email);
            } catch (\Exception) {
                // Ne pas bloquer la confirmation si le promo newsletter échoue
            }
        }

        $frontendUrl = rtrim((string) $this->params->get('app.frontend_url'), '/');

        return new RedirectResponse($frontendUrl . '/login?confirmed=true');
    }
}
