<?php

namespace App\Payment\EventSubscriber;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use App\Payment\Entity\Payment;
use App\Shared\Enum\PaymentStatus;
use App\Shipping\Service\ShippingLabelService;
use App\Shared\Service\MailerService;

class PaymentStatusSubscriber implements EventSubscriber
{
    public function __construct(
        private ShippingLabelService $shippingLabelService,
        private MailerService $mailerService,
    ) {}

    public function getSubscribedEvents(): array
    {
        return [Events::postUpdate];
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        // On agit uniquement sur les entités Payment
        if (!$entity instanceof Payment) {
            return;
        }

    

        // Si le statut passe à PAID
        if ($entity->getStatus() === PaymentStatus::PAID && !$entity->getOrder()->getShippingLabel()) {
            $label = $this->shippingLabelService->generateForOrder($entity->getOrder());
            //$this->mailerService->sendLabelToMerchant($label);
        }
    }
}
