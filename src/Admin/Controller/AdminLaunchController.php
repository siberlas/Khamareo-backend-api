<?php

namespace App\Admin\Controller;

use App\Marketing\Entity\PromoCode;
use App\Marketing\Repository\PromoCodeRepository;
use App\Shared\Entity\AppSettings;
use App\Shared\Repository\AppSettingsRepository;
use App\Shared\Repository\PreRegistrationRepository;
use App\Shared\Service\MailchimpService;
use App\Shared\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[AsController]
class AdminLaunchController
{
    private const PROMO_PREFIX        = 'AKWAABA';
    private const PROMO_DISCOUNT      = 10;
    private const PROMO_VALIDITY_DAYS = 30;

    public function __construct(
        private readonly EntityManagerInterface    $em,
        private readonly AppSettingsRepository     $settingsRepo,
        private readonly PreRegistrationRepository $preRegRepo,
        private readonly PromoCodeRepository       $promoCodeRepo,
        private readonly MailchimpService          $mailchimpService,
        private readonly MailerService             $mailerService,
    ) {}

    /**
     * POST /api/admin/coming-soon/launch
     *
     * 1. Upsert du code promo AKWABA
     * 2. Collecte des emails (pre_registration + Mailchimp) avec déduplication
     * 3. Envoi des emails d'annonce
     * 4. Désactivation du mode coming soon
     */
    #[Route('/api/admin/coming-soon/launch', name: 'admin_coming_soon_launch', methods: ['POST'])]
    public function launch(): JsonResponse
    {
        $expiresAt = new \DateTimeImmutable('+' . self::PROMO_VALIDITY_DAYS . ' days');

        // 1. Collecte des emails
        $emails = $this->collectEmails();

        // 2. Génération d'un code individuel par email + envoi
        $sent   = 0;
        $errors = 0;
        foreach ($emails as $email) {
            // Vérifier si un code launch existe déjà pour cet email
            $existing = $this->promoCodeRepo->findOneBy(['email' => $email, 'type' => 'launch']);
            if ($existing) {
                $promoCode = $existing;
                $promoCode->setExpiresAt($expiresAt);
                $promoCode->setIsActive(true);
            } else {
                $promoCode = $this->createLaunchPromoCode($email, $expiresAt);
                $this->em->persist($promoCode);
            }

            $this->em->flush();

            $ok = $this->mailerService->sendLaunchAnnouncement(
                $email,
                $promoCode->getCode(),
                self::PROMO_DISCOUNT,
                $expiresAt,
                'fr',
            );
            if ($ok) {
                ++$sent;
            } else {
                ++$errors;
            }
        }

        // 3. Désactivation du mode coming soon
        $this->upsertSetting('coming_soon_enabled', 'false');
        $this->em->flush();

        return new JsonResponse([
            'sent'        => $sent,
            'errors'      => $errors,
            'total'       => count($emails),
            'coming_soon' => false,
        ]);
    }

    // -------------------------------------------------------------------------

    private function createLaunchPromoCode(string $email, \DateTimeImmutable $expiresAt): PromoCode
    {
        $promo = new PromoCode();
        $promo->setCode(self::PROMO_PREFIX . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)));
        $promo->setType('launch');
        $promo->setEmail($email);
        $promo->setDiscountPercentage((string) self::PROMO_DISCOUNT);
        $promo->setEligibleCustomer('both');
        $promo->setMaxUses(1);              // 1 seule utilisation
        $promo->setMaxUsesPerEmail(1);
        $promo->setExpiresAt($expiresAt);
        $promo->setIsActive(true);
        $promo->setStackable(false);

        return $promo;
    }

    /**
     * Collecte et déduplique les emails depuis pre_registration et Mailchimp.
     *
     * @return string[]
     */
    private function collectEmails(): array
    {
        $emailSet = [];

        // Pré-inscrits depuis la base de données
        $preRegs = $this->preRegRepo->findAllOrderedByDate(1, 10000);
        foreach ($preRegs as $preReg) {
            $emailSet[strtolower($preReg->getEmail())] = true;
        }

        // Abonnés Mailchimp (si configuré)
        foreach ($this->mailchimpService->getSubscribedEmails() as $email) {
            $emailSet[$email] = true;
        }

        return array_keys($emailSet);
    }

    private function upsertSetting(string $key, ?string $value): void
    {
        $setting = $this->settingsRepo->findByKey($key);

        if ($setting) {
            $setting->setSettingValue($value);
        } else {
            $setting = new AppSettings($key, $value);
            $this->em->persist($setting);
        }
    }
}
