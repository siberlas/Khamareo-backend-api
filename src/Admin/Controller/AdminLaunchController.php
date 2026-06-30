<?php

namespace App\Admin\Controller;

use App\Marketing\Entity\NewsletterSubscriber;
use App\Marketing\Entity\PromoCode;
use App\Marketing\Repository\PromoCodeRepository;
use App\Shared\Entity\AppSettings;
use App\Shared\Entity\LaunchEmailQueue;
use App\Shared\Repository\AppSettingsRepository;
use App\Shared\Repository\LaunchEmailQueueRepository;
use App\Shared\Repository\PreRegistrationRepository;
use App\Shared\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
class AdminLaunchController
{
    private const PROMO_PREFIX        = 'AKWAABA';
    private const PROMO_DISCOUNT      = 10;
    private const PROMO_VALIDITY_DAYS = 30;

    public function __construct(
        private readonly EntityManagerInterface     $em,
        private readonly AppSettingsRepository      $settingsRepo,
        private readonly PreRegistrationRepository  $preRegRepo,
        private readonly PromoCodeRepository        $promoCodeRepo,
        private readonly LaunchEmailQueueRepository $queueRepo,
        private readonly MailerService              $mailerService,
    ) {}

    /**
     * POST /api/admin/coming-soon/prepare-launch
     *
     * Génère les codes promo et remplit la queue d'envoi.
     * Aucune date requise : les emails sont envoyés à l'ouverture.
     */
    #[Route('/api/admin/coming-soon/prepare-launch', name: 'admin_coming_soon_prepare_launch', methods: ['POST'])]
    public function prepareLaunch(): JsonResponse
    {
        $existingPending = $this->queueRepo->countByStatus(LaunchEmailQueue::STATUS_PENDING);
        if ($existingPending > 0) {
            return new JsonResponse([
                'error'   => 'Une préparation est déjà en cours.',
                'pending' => $existingPending,
            ], 409);
        }

        $today     = new \DateTimeImmutable('today');
        $expiresAt = $today->modify('+' . self::PROMO_VALIDITY_DAYS . ' days');

        $newsletterRepo   = $this->em->getRepository(NewsletterSubscriber::class);
        $newsletterEmails = [];
        foreach ($newsletterRepo->findAll() as $sub) {
            if ($sub->isConfirmed()) {
                $newsletterEmails[strtolower($sub->getEmail())] = true;
            }
        }

        $emails = $this->collectEmails();
        $queued = 0;

        foreach ($emails as $email) {
            $existing = $this->promoCodeRepo->findOneBy(['email' => $email, 'type' => 'launch']);
            if ($existing) {
                $promoCode = $existing;
                $promoCode->setExpiresAt($expiresAt);
                $promoCode->setIsActive(true);
            } else {
                $promoCode = $this->createLaunchPromoCode($email, $expiresAt);
                $this->em->persist($promoCode);
            }

            $queueItem = new LaunchEmailQueue();
            $queueItem->setEmail($email);
            $queueItem->setPromoCode($promoCode->getCode());
            $queueItem->setLaunchDate($today);
            $queueItem->setIsNewsletter(isset($newsletterEmails[strtolower($email)]));
            $this->em->persist($queueItem);

            ++$queued;

            if ($queued % 100 === 0) {
                $this->em->flush();
            }
        }

        $this->em->flush();

        $this->upsertSetting('pre_registration_closed', 'true');
        $this->upsertSetting('launch_preparation_status', 'ready');
        $this->em->flush();

        return new JsonResponse([
            'total_emails'  => $queued,
            'promo_expires' => $expiresAt->format('Y-m-d'),
            'status'        => 'ready',
        ]);
    }

    /**
     * GET /api/admin/coming-soon/launch-status
     */
    #[Route('/api/admin/coming-soon/launch-status', name: 'admin_coming_soon_launch_status', methods: ['GET'])]
    public function launchStatus(): JsonResponse
    {
        $stats      = $this->queueRepo->getStats();
        $prepStatus = $this->settingsRepo->findByKey('launch_preparation_status');

        return new JsonResponse([
            'total'    => $stats['total'],
            'sent'     => $stats['sent'],
            'pending'  => $stats['pending'],
            'failed'   => $stats['failed'],
            'status'   => $prepStatus?->getSettingValue() ?? 'idle',
            'progress' => $stats['total'] > 0
                ? round(($stats['sent'] / $stats['total']) * 100, 1)
                : 0,
        ]);
    }

    /**
     * POST /api/admin/coming-soon/send-all
     *
     * Envoie tous les emails en attente d'un coup.
     */
    #[Route('/api/admin/coming-soon/send-all', name: 'admin_coming_soon_send_all', methods: ['POST'])]
    public function sendAll(): JsonResponse
    {
        $batch = $this->queueRepo->findPendingBatch(100000);

        if (count($batch) === 0) {
            return new JsonResponse([
                'sent'    => 0,
                'errors'  => 0,
                'message' => 'Aucun email en attente.',
            ]);
        }

        $sent      = 0;
        $errors    = 0;
        $lastError = null;

        foreach ($batch as $item) {
            $expiresAt = $item->getLaunchDate()->modify('+' . self::PROMO_VALIDITY_DAYS . ' days');

            $result = $this->mailerService->sendLaunchAnnouncement(
                $item->getEmail(),
                $item->getPromoCode(),
                self::PROMO_DISCOUNT,
                $expiresAt,
                null,
                'fr',
                $item->isNewsletter(),
            );

            if ($result['success']) {
                $item->markAsSent();
                ++$sent;
            } else {
                $lastError = $result['error'] ?? 'Erreur inconnue';
                $item->markAsFailed($lastError);
                ++$errors;
            }

            if (($sent + $errors) % 50 === 0) {
                $this->em->flush();
            }
        }

        $this->upsertSetting('launch_preparation_status', 'completed');
        $this->em->flush();

        $remaining = $this->queueRepo->countByStatus(LaunchEmailQueue::STATUS_PENDING);

        return new JsonResponse([
            'sent'       => $sent,
            'errors'     => $errors,
            'remaining'  => $remaining,
            'last_error' => $lastError,
        ]);
    }

    /**
     * POST /api/admin/coming-soon/retry-failed
     */
    #[Route('/api/admin/coming-soon/retry-failed', name: 'admin_coming_soon_retry_failed', methods: ['POST'])]
    public function retryFailed(): JsonResponse
    {
        $failed = $this->queueRepo->findBy(['status' => LaunchEmailQueue::STATUS_FAILED]);
        $count  = 0;

        foreach ($failed as $item) {
            $item->setStatus(LaunchEmailQueue::STATUS_PENDING);
            $item->setErrorMessage(null);
            ++$count;
        }

        $this->em->flush();

        return new JsonResponse(['retried' => $count]);
    }

    /**
     * DELETE /api/admin/coming-soon/reset-preparation
     *
     * Remet la queue et les codes launch à zéro pour relancer prepare-launch.
     */
    #[Route('/api/admin/coming-soon/reset-preparation', name: 'admin_coming_soon_reset_preparation', methods: ['DELETE'])]
    public function resetPreparation(): JsonResponse
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE FROM launch_email_queue");
        $conn->executeStatement("DELETE FROM promo_code WHERE type = 'launch'");

        $this->upsertSetting('launch_preparation_status', 'idle');
        $this->em->flush();

        return new JsonResponse(['reset' => true]);
    }

    /**
     * POST /api/admin/coming-soon/open-site
     */
    #[Route('/api/admin/coming-soon/open-site', name: 'admin_coming_soon_open_site', methods: ['POST'])]
    public function openSite(): JsonResponse
    {
        $stats   = $this->queueRepo->getStats();
        $warning = $stats['pending'] > 0
            ? sprintf('%d email(s) n\'ont pas encore été envoyés.', $stats['pending'])
            : null;

        $this->upsertSetting('coming_soon_enabled', 'false');
        $this->upsertSetting('launch_preparation_status', 'completed');
        $this->em->flush();

        return new JsonResponse([
            'coming_soon' => false,
            'warning'     => $warning,
            'stats'       => $stats,
        ]);
    }

    // -------------------------------------------------------------------------

    /** @var array<string, true> */
    private array $generatedCodes = [];

    private function generateUniqueCode(): string
    {
        do {
            $code = self::PROMO_PREFIX . '-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
        } while (
            isset($this->generatedCodes[$code]) ||
            $this->promoCodeRepo->findOneBy(['code' => $code]) !== null
        );

        $this->generatedCodes[$code] = true;

        return $code;
    }

    private function createLaunchPromoCode(string $email, \DateTimeImmutable $expiresAt): PromoCode
    {
        $promo = new PromoCode();
        $promo->setCode($this->generateUniqueCode());
        $promo->setType('launch');
        $promo->setEmail($email);
        $promo->setDiscountPercentage((string) self::PROMO_DISCOUNT);
        $promo->setEligibleCustomer('both');
        $promo->setMaxUses(1);
        $promo->setMaxUsesPerEmail(1);
        $promo->setExpiresAt($expiresAt);
        $promo->setIsActive(true);
        $promo->setStackable(false);

        return $promo;
    }

    /** @return string[] */
    private function collectEmails(): array
    {
        $emailSet = [];

        foreach ($this->preRegRepo->findAllOrderedByDate(1, 10000) as $preReg) {
            $emailSet[strtolower($preReg->getEmail())] = true;
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
