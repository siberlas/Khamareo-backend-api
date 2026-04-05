<?php

namespace App\Marketing\EventSubscriber;

use App\Marketing\Entity\NewsletterSubscriber;
use App\Marketing\Service\PromoCodeService;
use App\Shared\Repository\AppSettingsRepository;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Psr\Log\LoggerInterface;

class PromoCodeSubscriber implements EventSubscriber
{
    public function __construct(
        private PromoCodeService $promoCodeService,
        private AppSettingsRepository $settingsRepo,
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

        // Inscription newsletter : envoyer le promo uniquement si le site est ouvert.
        // Pendant le coming soon, le promo est inclus dans le batch de lancement.
        if ($entity instanceof NewsletterSubscriber) {
            $comingSoon = $this->settingsRepo->findByKey('coming_soon_enabled');
            if ($comingSoon?->getSettingValue() === 'true') {
                return; // En mode coming soon, pas de promo auto
            }

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
    }
}