<?php

namespace App\Shared\Controller;

use App\Shared\Entity\AppSettings;
use App\Shared\Entity\PreRegistration;
use App\Shared\Repository\AppSettingsRepository;
use App\Shared\Repository\PreRegistrationRepository;
use App\Shared\Service\MailchimpService;
use App\Marketing\Entity\NewsletterSubscriber;
use App\Shared\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsController]
class ComingSoonController
{
    public function __construct(
        private readonly EntityManagerInterface    $em,
        private readonly AppSettingsRepository     $settingsRepo,
        private readonly PreRegistrationRepository $preRegRepo,
        private readonly ValidatorInterface        $validator,
        private readonly MailchimpService          $mailchimpService,
        private readonly MailerService             $mailerService,
    ) {}

    /**
     * GET /api/status
     * Retourne l'état du mode "coming soon".
     */
    #[Route('/api/status', name: 'api_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $enabledSetting    = $this->settingsRepo->findByKey('coming_soon_enabled');
        $launchDateSetting = $this->settingsRepo->findByKey('coming_soon_launch_date');

        $enabled    = $enabledSetting?->getSettingValue() === 'true';
        $launchDate = $launchDateSetting?->getSettingValue() ?: null;

        return new JsonResponse([
            'coming_soon' => $enabled,
            'launch_date' => $launchDate,
        ]);
    }

    /**
     * POST /api/pre-register
     * Enregistre un email de pré-inscription.
     */
    #[Route('/api/pre-register', name: 'api_pre_register', methods: ['POST'])]
    public function preRegister(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $email      = trim((string) ($data['email'] ?? ''));
        $consent    = (bool) ($data['consent'] ?? false);
        $newsletter = (bool) ($data['newsletter'] ?? false);

        // Validation email
        $emailErrors = $this->validator->validate($email, [
            new Assert\NotBlank(['message' => 'L\'adresse e-mail est requise.']),
            new Assert\Email(['message' => 'L\'adresse e-mail est invalide.']),
            new Assert\Length(['max' => 180]),
        ]);

        if (count($emailErrors) > 0) {
            return new JsonResponse(
                ['error' => $emailErrors[0]->getMessage()],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!$consent) {
            return new JsonResponse(
                ['error' => 'Le consentement est requis.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $messages = [];
        $alreadyRegistered = false;
        $newsletterStatus = null; // null, 'subscribed', 'pending_confirmation', 'already_confirmed'

        // 1. Vérifier si déjà pré-inscrit pour le lancement
        $existingPreReg = $this->preRegRepo->findByEmail($email);
        if ($existingPreReg) {
            $alreadyRegistered = true;
            $messages[] = 'Vous êtes déjà inscrit(e) pour l\'ouverture. Vous serez prévenu(e) et recevrez votre code promo dès le lancement !';
        } else {
            $preReg = new PreRegistration();
            $preReg->setEmail($email);
            $preReg->setConsentGiven(true);
            $preReg->setIpAddress($request->getClientIp());
            $this->em->persist($preReg);
            $messages[] = 'Inscription confirmée ! Vous serez prévenu(e) et recevrez votre code promo dès le lancement.';
        }

        // 2. Gérer la newsletter si demandée
        if ($newsletter) {
            $existingSubscriber = $this->em->getRepository(NewsletterSubscriber::class)
                ->findOneBy(['email' => $email]);

            if ($existingSubscriber) {
                if ($existingSubscriber->isConfirmed()) {
                    $newsletterStatus = 'already_confirmed';
                    $messages[] = 'Vous êtes déjà inscrit(e) à notre newsletter.';
                } else {
                    $newsletterStatus = 'pending_confirmation';
                    $messages[] = 'Votre inscription à la newsletter est en attente de confirmation. Vérifiez vos e-mails (et vos spams).';
                }
            } else {
                $subscriber = new NewsletterSubscriber();
                $subscriber->setEmail($email);
                $token = bin2hex(random_bytes(32));
                $subscriber->setConfirmationToken($token);
                $subscriber->setConfirmationSentAt(new \DateTimeImmutable());
                $this->em->persist($subscriber);

                $newsletterStatus = 'subscribed';
                $messages[] = 'Inscription à la newsletter effectuée ! Un e-mail de confirmation vous a été envoyé.';

                // Envoyer l'email de confirmation après flush
                $this->em->flush();

                try {
                    $backendUrl = $_ENV['BACKEND_BASE_URL'] ?? $_ENV['API_BASE_URL'] ?? 'https://api.khamareo.com';
                    $confirmUrl = $backendUrl . '/api/newsletter/confirm?token=' . $token;
                    $unsubscribeUrl = $backendUrl . '/api/newsletter/unsubscribe?token=' . $subscriber->getUnsubscribeToken();
                    $this->mailerService->sendNewsletterConfirmationEmail($subscriber, $confirmUrl, $unsubscribeUrl);
                } catch (\Throwable) {
                    // Email non envoyé, non bloquant
                }

                // Ajout silencieux à Mailchimp
                try {
                    $this->mailchimpService->addMember($email);
                } catch (\Throwable) {}

                return new JsonResponse([
                    'alreadyRegistered' => $alreadyRegistered,
                    'newsletterStatus' => $newsletterStatus,
                    'messages' => $messages,
                ], $alreadyRegistered ? Response::HTTP_OK : Response::HTTP_CREATED);
            }
        }

        $this->em->flush();

        // Ajout silencieux à Mailchimp (même sans newsletter)
        if (!$alreadyRegistered) {
            try {
                $this->mailchimpService->addMember($email);
            } catch (\Throwable) {}
        }

        return new JsonResponse([
            'alreadyRegistered' => $alreadyRegistered,
            'newsletterStatus' => $newsletterStatus,
            'messages' => $messages,
        ], $alreadyRegistered ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    /**
     * POST /api/pre-register/resend-newsletter
     * Renvoyer l'email de confirmation newsletter.
     */
    #[Route('/api/pre-register/resend-newsletter', name: 'api_pre_register_resend_newsletter', methods: ['POST'])]
    public function resendNewsletterConfirmation(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $email = trim((string) ($data['email'] ?? ''));

        $subscriber = $this->em->getRepository(NewsletterSubscriber::class)
            ->findOneBy(['email' => $email]);

        if (!$subscriber) {
            return new JsonResponse(['error' => 'Aucune inscription newsletter trouvée.'], Response::HTTP_NOT_FOUND);
        }

        if ($subscriber->isConfirmed()) {
            return new JsonResponse(['message' => 'Votre newsletter est déjà confirmée.'], Response::HTTP_OK);
        }

        // Nouveau token
        $token = bin2hex(random_bytes(32));
        $subscriber->setConfirmationToken($token);
        $subscriber->setConfirmationSentAt(new \DateTimeImmutable());
        $this->em->flush();

        try {
            $backendUrl = $_ENV['BACKEND_BASE_URL'] ?? $_ENV['API_BASE_URL'] ?? 'https://api.khamareo.com';
            $confirmUrl = $backendUrl . '/api/newsletter/confirm?token=' . $token;
            $unsubscribeUrl = $backendUrl . '/api/newsletter/unsubscribe?token=' . $subscriber->getUnsubscribeToken();
            $this->mailerService->sendNewsletterConfirmationEmail($subscriber, $confirmUrl, $unsubscribeUrl);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Erreur lors de l\'envoi. Réessayez.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(['message' => 'E-mail de confirmation renvoyé avec succès.'], Response::HTTP_OK);
    }
}
