<?php

namespace App\Shared\Controller;

use App\Shared\Entity\AppSettings;
use App\Shared\Entity\PreRegistration;
use App\Shared\Repository\AppSettingsRepository;
use App\Shared\Repository\PreRegistrationRepository;
use App\Shared\Service\MailchimpService;
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

        $email   = trim((string) ($data['email'] ?? ''));
        $consent = (bool) ($data['consent'] ?? false);

        // Validation
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

        // Déduplication
        if ($this->preRegRepo->findByEmail($email)) {
            return new JsonResponse(
                ['error' => 'Cette adresse e-mail est déjà enregistrée.'],
                Response::HTTP_CONFLICT
            );
        }

        $preReg = new PreRegistration();
        $preReg->setEmail($email);
        $preReg->setConsentGiven(true);
        $preReg->setIpAddress($request->getClientIp());

        $this->em->persist($preReg);
        $this->em->flush();

        // Ajout silencieux à Mailchimp (échec non bloquant)
        try {
            $this->mailchimpService->addMember($email);
        } catch (\Throwable) {
            // Mailchimp non configuré ou erreur réseau : on continue
        }

        return new JsonResponse(
            ['message' => 'Inscription enregistrée avec succès.'],
            Response::HTTP_CREATED
        );
    }
}
