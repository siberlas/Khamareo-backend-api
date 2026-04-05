<?php

namespace App\Admin\Controller;

use App\Marketing\Entity\PromoCode;
use App\Marketing\Repository\PromoCodeRepository;
use App\Shared\Entity\AppSettings;
use App\Shared\Entity\LaunchEmailQueue;
use App\Shared\Repository\AppSettingsRepository;
use App\Shared\Repository\LaunchEmailQueueRepository;
use App\Shared\Repository\PreRegistrationRepository;
use App\Shared\Service\MailchimpService;
use App\Shared\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[AsController]
class AdminLaunchController
{
    private const PROMO_PREFIX        = 'AKWAABA';
    private const PROMO_DISCOUNT      = 10;
    private const PROMO_VALIDITY_DAYS = 30;
    private const DEFAULT_DAILY_LIMIT = 290;

    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly AppSettingsRepository       $settingsRepo,
        private readonly PreRegistrationRepository   $preRegRepo,
        private readonly PromoCodeRepository         $promoCodeRepo,
        private readonly LaunchEmailQueueRepository  $queueRepo,
        private readonly MailchimpService            $mailchimpService,
        private readonly MailerService               $mailerService,
    ) {}

    /**
     * POST /api/admin/coming-soon/prepare-launch
     *
     * Étape 1 : Ferme les pré-inscriptions, génère les codes promo,
     * remplit la queue d'envoi et retourne le planning.
     *
     * Body JSON : { "launch_date": "2026-04-15" }
     */
    #[Route('/api/admin/coming-soon/prepare-launch', name: 'admin_coming_soon_prepare_launch', methods: ['POST'])]
    public function prepareLaunch(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $launchDateStr = $data['launch_date'] ?? null;

        if (!$launchDateStr) {
            return new JsonResponse(['error' => 'Le champ launch_date est requis (format YYYY-MM-DD).'], 400);
        }

        try {
            $launchDate = new \DateTimeImmutable($launchDateStr);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Format de date invalide. Utilisez YYYY-MM-DD.'], 400);
        }

        // Vérifier qu'il n'y a pas déjà une préparation en cours
        $existingPending = $this->queueRepo->countByStatus(LaunchEmailQueue::STATUS_PENDING);
        if ($existingPending > 0) {
            return new JsonResponse([
                'error' => 'Une préparation est déjà en cours.',
                'pending' => $existingPending,
            ], 409);
        }

        $dailyLimit = $this->getDailyLimit();

        // Vérifier que la date d'ouverture laisse assez de temps
        $emails = $this->collectEmails();
        $totalEmails = count($emails);
        $daysNeeded = (int) ceil($totalEmails / $dailyLimit);
        $today = new \DateTimeImmutable('today');
        $minLaunchDate = $today->modify('+' . $daysNeeded . ' days');

        if ($launchDate < $minLaunchDate) {
            return new JsonResponse([
                'error' => sprintf(
                    'Date trop proche. Avec %d inscrits et %d emails/jour, il faut %d jour(s) d\'envoi. Date minimum : %s.',
                    $totalEmails,
                    $dailyLimit,
                    $daysNeeded,
                    $minLaunchDate->format('Y-m-d')
                ),
                'total_emails'     => $totalEmails,
                'daily_limit'      => $dailyLimit,
                'days_needed'      => $daysNeeded,
                'min_launch_date'  => $minLaunchDate->format('Y-m-d'),
            ], 422);
        }

        $expiresAt = $launchDate->modify('+' . self::PROMO_VALIDITY_DAYS . ' days');

        // Générer les codes promo + remplir la queue
        $queued = 0;
        foreach ($emails as $email) {
            // Upsert du code promo
            $existing = $this->promoCodeRepo->findOneBy(['email' => $email, 'type' => 'launch']);
            if ($existing) {
                $promoCode = $existing;
                $promoCode->setExpiresAt($expiresAt);
                $promoCode->setIsActive(true);
            } else {
                $promoCode = $this->createLaunchPromoCode($email, $expiresAt);
                $this->em->persist($promoCode);
            }

            // Créer l'entrée dans la queue
            $queueItem = new LaunchEmailQueue();
            $queueItem->setEmail($email);
            $queueItem->setPromoCode($promoCode->getCode());
            $queueItem->setLaunchDate($launchDate);
            $this->em->persist($queueItem);

            ++$queued;

            // Flush par batch de 100 pour éviter les problèmes mémoire
            if ($queued % 100 === 0) {
                $this->em->flush();
            }
        }

        $this->em->flush();

        // Marquer les pré-inscriptions comme fermées + sauver la date
        $this->upsertSetting('pre_registration_closed', 'true');
        $this->upsertSetting('coming_soon_launch_date', $launchDate->format('Y-m-d'));
        $this->upsertSetting('launch_preparation_status', 'sending');
        $this->em->flush();

        return new JsonResponse([
            'total_emails'    => $totalEmails,
            'daily_limit'     => $dailyLimit,
            'days_needed'     => $daysNeeded,
            'launch_date'     => $launchDate->format('Y-m-d'),
            'promo_expires'   => $expiresAt->format('Y-m-d'),
            'status'          => 'sending',
        ]);
    }

    /**
     * GET /api/admin/coming-soon/launch-status
     *
     * Retourne la progression de l'envoi des emails.
     */
    #[Route('/api/admin/coming-soon/launch-status', name: 'admin_coming_soon_launch_status', methods: ['GET'])]
    public function launchStatus(): JsonResponse
    {
        $stats = $this->queueRepo->getStats();
        $dailyLimit = $this->getDailyLimit();
        $daysRemaining = $stats['pending'] > 0
            ? (int) ceil($stats['pending'] / $dailyLimit)
            : 0;

        $launchDate = $this->settingsRepo->findByKey('coming_soon_launch_date');
        $prepStatus = $this->settingsRepo->findByKey('launch_preparation_status');
        $lastBatchSetting = $this->settingsRepo->findByKey('last_batch_sent_at');
        $lastBatchAt = $lastBatchSetting?->getSettingValue();

        // Calcul du verrou 24h
        $locked = false;
        $unlockAt = null;
        if ($lastBatchAt) {
            try {
                $lastBatchTime = new \DateTimeImmutable($lastBatchAt);
                $nextAllowed = $lastBatchTime->modify('+24 hours');
                $now = new \DateTimeImmutable();
                if ($now < $nextAllowed) {
                    $locked = true;
                    $unlockAt = $nextAllowed->format('c');
                }
            } catch (\Exception) {}
        }

        return new JsonResponse([
            'total'          => $stats['total'],
            'sent'           => $stats['sent'],
            'pending'        => $stats['pending'],
            'failed'         => $stats['failed'],
            'daily_limit'    => $dailyLimit,
            'days_remaining' => $daysRemaining,
            'launch_date'    => $launchDate?->getSettingValue(),
            'status'         => $prepStatus?->getSettingValue() ?? 'idle',
            'progress'       => $stats['total'] > 0
                ? round(($stats['sent'] / $stats['total']) * 100, 1)
                : 0,
            'locked'         => $locked,
            'unlock_at'      => $unlockAt,
            'last_batch_at'  => $lastBatchAt,
        ]);
    }

    /**
     * POST /api/admin/coming-soon/open-site
     *
     * Étape finale : désactive le mode coming soon et ouvre le site.
     */
    #[Route('/api/admin/coming-soon/open-site', name: 'admin_coming_soon_open_site', methods: ['POST'])]
    public function openSite(): JsonResponse
    {
        $stats = $this->queueRepo->getStats();

        // Avertir s'il reste des emails non envoyés
        $warning = null;
        if ($stats['pending'] > 0) {
            $warning = sprintf('%d email(s) n\'ont pas encore été envoyés.', $stats['pending']);
        }

        $this->upsertSetting('coming_soon_enabled', 'false');
        $this->upsertSetting('launch_preparation_status', 'completed');
        $this->em->flush();

        return new JsonResponse([
            'coming_soon' => false,
            'warning'     => $warning,
            'stats'       => $stats,
        ]);
    }

    /**
     * POST /api/admin/coming-soon/send-batch
     *
     * Envoie manuellement le prochain batch d'emails.
     * Verrouillé pendant 24h après chaque envoi.
     */
    #[Route('/api/admin/coming-soon/send-batch', name: 'admin_coming_soon_send_batch', methods: ['POST'])]
    public function sendBatch(): JsonResponse
    {
        // Vérifier le verrou 24h
        $lastBatchSetting = $this->settingsRepo->findByKey('last_batch_sent_at');
        if ($lastBatchSetting?->getSettingValue()) {
            try {
                $lastBatchTime = new \DateTimeImmutable($lastBatchSetting->getSettingValue());
                $nextAllowed = $lastBatchTime->modify('+24 hours');
                $now = new \DateTimeImmutable();
                if ($now < $nextAllowed) {
                    $remaining = $now->diff($nextAllowed);
                    return new JsonResponse([
                        'error' => sprintf(
                            'Prochain envoi possible dans %dh%02d.',
                            $remaining->h + ($remaining->days * 24),
                            $remaining->i
                        ),
                        'locked'    => true,
                        'unlock_at' => $nextAllowed->format('c'),
                    ], 429);
                }
            } catch (\Exception) {}
        }

        $dailyLimit = $this->getDailyLimit();
        $batch = $this->queueRepo->findPendingBatch($dailyLimit);

        if (count($batch) === 0) {
            return new JsonResponse([
                'sent' => 0,
                'errors' => 0,
                'message' => 'Aucun email en attente.',
            ]);
        }

        $sent = 0;
        $errors = 0;
        $stoppedByRateLimit = false;
        $lastError = null;

        foreach ($batch as $item) {
            $result = $this->mailerService->sendLaunchAnnouncement(
                $item->getEmail(),
                $item->getPromoCode(),
                self::PROMO_DISCOUNT,
                $item->getLaunchDate()->modify('+' . self::PROMO_VALIDITY_DAYS . ' days'),
                $item->getLaunchDate(),
                'fr',
            );

            if ($result['success']) {
                $item->markAsSent();
                ++$sent;
            } else {
                $errorMsg = $result['error'] ?? 'Erreur inconnue';
                $lastError = $errorMsg;
                $errorLower = strtolower($errorMsg);

                // Détecter les erreurs de limite Brevo (rate limit / quota)
                $isRateLimit = str_contains($errorLower, 'rate limit')
                    || str_contains($errorLower, 'quota')
                    || str_contains($errorLower, 'too many')
                    || str_contains($errorLower, '429')
                    || str_contains($errorLower, 'daily limit')
                    || str_contains($errorLower, 'sending limit');

                if ($isRateLimit) {
                    // Ne pas marquer comme failed — cet email sera réessayé
                    $stoppedByRateLimit = true;
                    $this->em->flush();
                    break;
                }

                $item->markAsFailed($errorMsg);
                ++$errors;
            }

            // Flush par batch de 50
            if (($sent + $errors) % 50 === 0) {
                $this->em->flush();
            }
        }

        // Sauvegarder le timestamp du dernier envoi (verrou 24h)
        $this->upsertSetting('last_batch_sent_at', (new \DateTimeImmutable())->format('c'));
        $this->em->flush();

        $remaining = $this->queueRepo->countByStatus(LaunchEmailQueue::STATUS_PENDING);

        return new JsonResponse([
            'sent'               => $sent,
            'errors'             => $errors,
            'remaining'          => $remaining,
            'locked'             => true,
            'stopped_by_limit'   => $stoppedByRateLimit,
            'last_error'         => $lastError,
        ]);
    }

    /**
     * POST /api/admin/coming-soon/retry-failed
     *
     * Remet les emails en erreur dans la queue.
     */
    #[Route('/api/admin/coming-soon/retry-failed', name: 'admin_coming_soon_retry_failed', methods: ['POST'])]
    public function retryFailed(): JsonResponse
    {
        $failed = $this->queueRepo->findBy(['status' => LaunchEmailQueue::STATUS_FAILED]);
        $count = 0;

        foreach ($failed as $item) {
            $item->setStatus(LaunchEmailQueue::STATUS_PENDING);
            $item->setErrorMessage(null);
            ++$count;
        }

        $this->em->flush();

        return new JsonResponse(['retried' => $count]);
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
        $promo->setMaxUses(1);
        $promo->setMaxUsesPerEmail(1);
        $promo->setExpiresAt($expiresAt);
        $promo->setIsActive(true);
        $promo->setStackable(false);

        return $promo;
    }

    /**
     * @return string[]
     */
    private function collectEmails(): array
    {
        $emailSet = [];

        $preRegs = $this->preRegRepo->findAllOrderedByDate(1, 10000);
        foreach ($preRegs as $preReg) {
            $emailSet[strtolower($preReg->getEmail())] = true;
        }

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

    private function getDailyLimit(): int
    {
        $setting = $this->settingsRepo->findByKey('daily_email_limit');
        if ($setting && $setting->getSettingValue()) {
            return (int) $setting->getSettingValue();
        }

        return self::DEFAULT_DAILY_LIMIT;
    }
}
