<?php

namespace App\Marketing\Service;

use App\Marketing\Entity\PromoCode;
use App\Marketing\Repository\PromoCodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use App\Shared\Service\MailerService;
use Symfony\Component\HttpFoundation\RequestStack;

class PromoCodeService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PromoCodeRepository $promoCodeRepository,
        private MailerService $mailerService,
        private LoggerInterface $logger,
        private RequestStack $requestStack
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
        $promoCode->setStackable(true);
        $promoCode->setExpiresAt(
            (new \DateTimeImmutable())->modify("+{$validityDays} days")
        );

        $this->entityManager->persist($promoCode);
        $this->entityManager->flush();

        return $promoCode;
    }

    /**
     * Récupère la locale de la requête courante
     */
    private function getCurrentLocale(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        return $request?->getLocale() ?? 'fr';
    }


    /**
     * Vérifie si un promo d'un type donné a déjà été envoyé à cet email (règle "une seule fois")
     */
    public function hasReceivedPromo(string $email, string $type): bool
    {
        return $this->promoCodeRepository->findOneBy(['email' => $email, 'type' => $type]) !== null;
    }

    /**
     * Envoie le promo newsletter — une seule fois, même après désabonnement/réabonnement.
     * Retourne null si le promo a déjà été reçu.
     */
    public function handleNewsletterSubscription(string $email): ?PromoCode
    {
        if ($this->hasReceivedPromo($email, 'newsletter')) {
            $this->logger->info('Newsletter promo already sent, skipping', ['email' => $email]);
            return null;
        }

        $locale = $this->getCurrentLocale();

        $promoCode = $this->createPromoCode(
            email: $email,
            type: 'newsletter',
            discountPercentage: 10.0,
            validityDays: 30
        );

        $this->mailerService->sendPromoCodeEmail($promoCode, $locale);

        return $promoCode;
    }

    public function handleFirstOrder(string $email): PromoCode
    {
        $locale = $this->getCurrentLocale();

        $promoCode = $this->createPromoCode(
            email: $email,
            type: 'first_order',
            discountAmount: 5.0,
            validityDays: 60
        );

        $this->mailerService->sendPromoCodeEmail($promoCode, $locale);

        return $promoCode;
    }

    /**
     * Envoie le promo inscription — une seule fois (même si compte supprimé/recréé).
     * Retourne null si déjà reçu.
     */
    public function handleUserRegistration(string $email): ?PromoCode
    {
        if ($this->hasReceivedPromo($email, 'registration')) {
            $this->logger->info('Registration promo already sent, skipping', ['email' => $email]);
            return null;
        }

        $locale = $this->getCurrentLocale();
        $promoCode = $this->createPromoCode(
            email: $email,
            type: 'registration',
            discountPercentage: 15.0,
            validityDays: 45
        );

        $this->mailerService->sendPromoCodeEmail($promoCode, $locale);

        return $promoCode;
    }
}