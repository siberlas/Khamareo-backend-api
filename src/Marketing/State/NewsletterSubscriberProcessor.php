<?php

namespace App\Marketing\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Marketing\Entity\NewsletterSubscriber;
use App\Marketing\Service\PromoCodeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

class NewsletterSubscriberProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PromoCodeService $promoCodeService
    ) {}

    /**
     * @param NewsletterSubscriber $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): NewsletterSubscriber
    {
        // Sauvegarder l'abonné
        $this->entityManager->persist($data);
        $this->entityManager->flush();

        // Créer et envoyer le code promo
        try {
            $promoCode = $this->promoCodeService->handleNewsletterSubscription(
                $data->getEmail()
            );
            
            // Ajouter le code promo à la réponse
            $data->setPromoCode($promoCode->getCode());
             $data->setPromoDiscountPercentage(
                $promoCode->getDiscountPercentage() ? (float) $promoCode->getDiscountPercentage() : null
            );
            $data->setPromoDiscountAmount(
                $promoCode->getDiscountAmount() ? (float) $promoCode->getDiscountAmount() : null
            );
            $data->setPromoExpiresAt($promoCode->getExpiresAt());
            
        } catch (\Exception $e) {
          throw $e;
        }

        return $data;
    }
}