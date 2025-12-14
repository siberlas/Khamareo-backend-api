<?php

namespace App\EventSubscriber;

use App\Entity\NewsletterSubscriber;
use App\Service\PromoCodeService;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Psr\Log\LoggerInterface;

class PromoCodeSubscriber implements EventSubscriber
{
    public function __construct(
        private PromoCodeService $promoCodeService,
        private LoggerInterface $logger
    ) {}

    public function getSubscribedEvents(): array
    {
        return [
            Events::postPersist,
        ];
    }

    public function postPersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        // Inscription newsletter
        if ($entity instanceof NewsletterSubscriber) {
            try {
                $this->promoCodeService->handleNewsletterSubscription(
                    $entity->getEmail()
                );
            } catch (\Exception $e) {
                $this->logger->error('Failed to create promo code for newsletter', [
                    'email' => $entity->getEmail(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Vous pouvez ajouter d'autres conditions ici
        // Par exemple : if ($entity instanceof Order && $entity->isFirstOrder())
    }
}