<?php

namespace App\Marketing\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Marketing\Entity\NewsletterSubscriber;
use App\Shared\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class NewsletterSubscriberProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MailerService $mailerService,
        private LoggerInterface $logger,
        private string $backendUrl,
    ) {}

    /**
     * @param NewsletterSubscriber $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): NewsletterSubscriber
    {
        // Générer les tokens de confirmation et de désabonnement
        $data->setConfirmationToken(bin2hex(random_bytes(32)));
        $data->setUnsubscribeToken(bin2hex(random_bytes(32)));
        $data->setConfirmationSentAt(new \DateTimeImmutable());

        $this->entityManager->persist($data);
        $this->entityManager->flush();

        // Envoyer l'email de confirmation (double opt-in) — PAS de promo ici
        try {
            $confirmUrl     = $this->backendUrl . '/api/newsletter/confirm?token=' . $data->getConfirmationToken();
            $unsubscribeUrl = $this->backendUrl . '/api/newsletter/unsubscribe?token=' . $data->getUnsubscribeToken();
            $this->mailerService->sendNewsletterConfirmationEmail($data, $confirmUrl, $unsubscribeUrl);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send newsletter confirmation email', [
                'email' => $data->getEmail(),
                'error' => $e->getMessage(),
            ]);
        }

        return $data;
    }
}