<?php

namespace App\Marketing\EventListener;

use App\Marketing\Entity\HeroSlide;
use App\Media\Service\MediaService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::preRemove)]
class HeroSlideDeleteListener
{
    public function __construct(private MediaService $mediaService)
    {
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof HeroSlide) {
            return;
        }

        $media = $entity->getMedia();

        if (!$media) {
            return;
        }

        $this->mediaService->deleteMediaIfUnused($media, $entity->getId());
    }
}