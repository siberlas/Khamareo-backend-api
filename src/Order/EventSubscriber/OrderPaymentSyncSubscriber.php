<?php

namespace App\Order\EventSubscriber;

use App\Payment\Entity\Payment;
use App\Shared\Enum\PaymentStatus;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\PostUpdateEventArgs;

class OrderPaymentSyncSubscriber implements EventSubscriber
{
    public function getSubscribedEvents(): array
    {
        return [Events::postUpdate];
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Payment) {
            return;
        }

        $order = $entity->getOrder();
        if (!$order) {
            return;
        }

        // Synchronisation automatique
        $order->setPaymentStatus($entity->getStatus());

        // Optionnel : synchronise aussi le statut global
        if ($entity->getStatus() === PaymentStatus::PAID) {
            $order->setStatus(\App\Enum\OrderStatus::PAID);
        } elseif ($entity->getStatus() === PaymentStatus::FAILED) {
            $order->setStatus(\App\Enum\OrderStatus::CANCELLED);
        }
    }
}
