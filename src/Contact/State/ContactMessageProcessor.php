<?php

namespace App\Contact\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Contact\Entity\ContactMessage;
use App\Shared\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;

final class ContactMessageProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private MailerService $mailerService,
        private RequestStack $requestStack,
        private LoggerInterface $logger
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ContactMessage
    {
        assert($data instanceof ContactMessage);

        // Récupérer la requête et la locale
        $request = $this->requestStack->getCurrentRequest();
        
        $this->logger->info('📧 ContactMessage - Request received', [
            'has_request' => $request !== null,
            'accept_language' => $request?->headers->get('Accept-Language'),
            'current_locale' => $request?->getLocale(),
        ]);

        $locale = $request?->getLocale() ?? 'fr';
        
        $this->logger->info('📧 ContactMessage - Locale determined', [
            'locale' => $locale,
            'email' => $data->getEmail(),
            'name' => $data->getName(),
        ]);

        $this->em->persist($data);
        $this->em->flush();

        // Envoyer les emails AVEC la locale
        $this->mailerService->sendContactNotification($data);
        $this->mailerService->sendContactConfirmation($data, $locale); // ← ICI !

        $this->logger->info('✅ ContactMessage - Emails sent', [
            'locale' => $locale,
        ]);

        return $data;
    }
}