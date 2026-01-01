<?php

namespace App\Order\EventSubscriber;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Event\LifecycleEventArgs;
use App\User\Entity\Address;

class ShippingAddressDefaultSubscriber implements EventSubscriber
{
    public function getSubscribedEvents(): array
    {
        return [
            Events::prePersist,
            Events::preUpdate,
        ];
    }

    public function prePersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof Address) {
            return;
        }

        $this->handleDefaultAddress($entity, $args);
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof Address) {
            return;
        }

        // On agit seulement si isDefault passe à true
        if (!$args->hasChangedField('isDefault') || $args->getNewValue('isDefault') !== true) {
            return;
        }

        $this->handleDefaultAddress($entity, $args);
    }

    private function handleDefaultAddress(Address $address, object $args): void
    {
        if ($address->isDefault() !== true) {
            return;
        }

        $user = $address->getOwner();
        if (!$user) {
            return;
        }

        $em = $args->getObjectManager();
        $repo = $em->getRepository(Address::class);

        // Récupérer toutes les autres adresses du user
        $otherAddresses = $repo->findBy(['owner' => $user]);

        foreach ($otherAddresses as $other) {
            if ($other !== $address && $other->isDefault() === true) {
                $other->setIsDefault(false);
            }
        }
    }
}
