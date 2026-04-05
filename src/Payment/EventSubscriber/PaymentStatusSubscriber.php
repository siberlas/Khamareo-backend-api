<?php

namespace App\Payment\EventSubscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use App\Payment\Entity\Payment;
use App\Shared\Enum\PaymentStatus;
use App\Shipping\Service\ShippingLabelService;
use App\Shared\Service\MailerService;

#[AsDoctrineListener(event: 'postUpdate')]
class PaymentStatusSubscriber
{
    public function __construct(
        private ShippingLabelService $shippingLabelService,
        private MailerService $mailerService,
    ) {}

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

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
