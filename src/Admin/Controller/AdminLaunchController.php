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
    private const PROMO_CODE        = 'AKWABA';
    private const PROMO_DISCOUNT    = 10;
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
        // 1. Upsert du code promo AKWABA
        $expiresAt = new \DateTimeImmutable('+' . self::PROMO_VALIDITY_DAYS . ' days');
        $promoCode = $this->upsertAkwabaPromoCode($expiresAt);

        // 2. Collecte des emails
        $emails = $this->collectEmails();

        // 3. Envoi des emails
        $sent   = 0;
        $errors = 0;
        foreach ($emails as $email) {
            $ok = $this->mailerService->sendLaunchAnnouncement(
                $email,
                self::PROMO_CODE,
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

        // 4. Désactivation du mode coming soon
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

    private function upsertAkwabaPromoCode(\DateTimeImmutable $expiresAt): PromoCode
    {
        $existing = $this->promoCodeRepo->findOneBy(['code' => self::PROMO_CODE]);

        if ($existing) {
            // Met à jour la date d'expiration sans toucher aux autres champs
            $existing->setExpiresAt($expiresAt);
            $this->em->flush();
            return $existing;
        }

        $promo = new PromoCode();
        $promo->setCode(self::PROMO_CODE);
        $promo->setType('manual');
        $promo->setDiscountPercentage((string) self::PROMO_DISCOUNT);
        $promo->setEligibleCustomer('both');
        $promo->setMaxUses(null);           // illimité
        $promo->setMaxUsesPerEmail(1);      // 1 utilisation par email
        $promo->setExpiresAt($expiresAt);
        $promo->setIsActive(true);

        $this->em->persist($promo);
        $this->em->flush();

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
