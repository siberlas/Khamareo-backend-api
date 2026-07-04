<?php

namespace App\Contact\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Contact\Entity\ContactConversation;
use App\Contact\Entity\ContactMessage;
use App\Contact\Repository\ContactConversationRepository;
use App\Shared\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class ContactMessageProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private ContactConversationRepository $conversationRepo,
        private MailerService $mailerService,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ContactMessage
    {
        assert($data instanceof ContactMessage);

        $request = $this->requestStack->getCurrentRequest();
        $locale = $request?->getLocale() ?? 'fr';

        // Chercher une conversation existante pour cet email
        $conversation = $this->conversationRepo->findByEmail($data->getEmail());

        if ($conversation === null) {
            $conversation = new ContactConversation();
            $conversation->setEmail($data->getEmail());
            $conversation->setName($data->getName());
            $conversation->setSubject($data->getSubject());
            $conversation->setLocale($locale);
            $this->em->persist($conversation);
        } else {
            // Nouveau message dans une conversation existante
            $conversation->setHasNew(true);
            $conversation->setIsRead(false);
            $this->logger->info('📧 ContactMessage - New message in existing conversation', [
                'conversation_id' => $conversation->getId(),
                'email' => $data->getEmail(),
            ]);
        }

        $conversation->setLastMessageAt(new \DateTimeImmutable());
        $conversation->addMessage($data);

        $this->em->persist($data);
        $this->em->flush();

        $this->mailerService->sendContactNotification($data);
        $this->mailerService->sendContactConfirmation($data, $locale);

        return $data;
    }
}
