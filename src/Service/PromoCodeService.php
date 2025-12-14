<?php

namespace App\Service;

use App\Entity\PromoCode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Psr\Log\LoggerInterface;
use App\Service\MailerService;

class PromoCodeService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MailerService $mailerService,
        private LoggerInterface $logger,
    ) {}

    public function generateCode(): string
    {
        return strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 10));
    }

    public function createPromoCode(
        string $email,
        string $type,
        ?float $discountPercentage = null,
        ?float $discountAmount = null,
        int $validityDays = 30
    ): PromoCode {
        $promoCode = new PromoCode();
        $promoCode->setCode($this->generateCode());
        $promoCode->setEmail($email);
        $promoCode->setType($type);
        $promoCode->setDiscountPercentage($discountPercentage);
        $promoCode->setDiscountAmount($discountAmount);
        $promoCode->setExpiresAt(
            (new \DateTimeImmutable())->modify("+{$validityDays} days")
        );

        $this->entityManager->persist($promoCode);
        $this->entityManager->flush();

        return $promoCode;
    }

    
    public function handleNewsletterSubscription(string $email): Promocode
    {
        $promoCode = $this->createPromoCode(
            email: $email,
            type: 'newsletter',
            discountPercentage: 10.0,
            validityDays: 30
        );

        $this->mailerService->sendPromoCodeEmail($promoCode);

        return $promoCode;
    }

    public function handleFirstOrder(string $email): Promocode
    {
        $promoCode = $this->createPromoCode(
            email: $email,
            type: 'first_order',
            discountAmount: 5.0,
            validityDays: 60
        );

        $this->mailerService->sendPromoCodeEmail($promoCode);

        return $promoCode;
    }

    public function handleUserRegistration(string $email): PromoCode
    {
        $promoCode = $this->createPromoCode(
            email: $email,
            type: 'registration', // nouveau type
            discountPercentage: 15.0, // 15% de réduction pour les nouveaux inscrits
            validityDays: 45 // 45 jours de validité
        );

        $this->mailerService->sendPromoCodeEmail($promoCode);

        return $promoCode;
    }
}