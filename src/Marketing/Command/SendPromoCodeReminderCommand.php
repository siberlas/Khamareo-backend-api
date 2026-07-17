<?php

namespace App\Marketing\Command;

use App\Catalog\Repository\ProductRepository;
use App\Marketing\Entity\EmailSendLog;
use App\Marketing\Entity\PromoCode;
use App\Marketing\Repository\PromoCodeRepository;
use App\Shared\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Segment 3 du cron marketing : relance des codes promo non utilisés
 * (launch/AKWAABA 30j, first_order 60j, newsletter 90j, registration 120j).
 *
 * Cascade commune à tous les types :
 *   - Email 1 (rappel) à J+3 après création — 3 best-sellers, Kigelia en priorité.
 *   - Mises à jour mensuelles à J+7 et J-3 de chaque tranche de 30 jours,
 *     SAUF le dernier mois avant expiration (codes 60j/90j/120j uniquement —
 *     un code de 30j n'a qu'un seul mois, donc aucune mise à jour).
 *   - Email 2 (urgence) à J-3 avant expiration, remplace la mise à jour du
 *     dernier mois.
 *
 * Les codes déjà anciens au premier lancement de cette commande reçoivent
 * immédiatement l'Email 1 (rattrapage naturel, pas de script séparé).
 */
#[AsCommand(
    name: 'app:send-promo-code-reminder',
    description: 'Relance des codes promo non utilisés (rappel, mises à jour mensuelles, urgence J-3)'
)]
class SendPromoCodeReminderCommand extends Command
{
    private const TYPES = ['launch', 'first_order', 'newsletter', 'registration'];
    private const RAPPEL_MIN_AGE_DAYS = 3;
    private const URGENCY_DAYS_BEFORE_EXPIRY = 3;
    private const MONTH_LENGTH_DAYS = 30;

    public function __construct(
        private readonly PromoCodeRepository $promoCodeRepository,
        private readonly ProductRepository $productRepository,
        private readonly MailerService $mailerService,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();

        $sentByAction = ['rappel' => 0, 'update' => 0, 'urgency' => 0];

        foreach ($this->getUnusedCodes() as $promoCode) {
            $action = self::nextAction($promoCode, $now);
            if ($action === null) {
                continue;
            }

            try {
                $shownIds = self::parseProductIds($promoCode->getReminderLastProductIds());

                if ($action === 'rappel') {
                    $products = $this->pickProducts(3, $shownIds);
                    $this->mailerService->sendPromoCodeReminderRappel($promoCode, $products);
                    $promoCode->setReminderRappelSentAt($now);
                    $promoCode->setReminderLastProductIds(implode(',', array_map(fn(array $p) => (string) $p['id'], $products)));
                } elseif ($action === 'update') {
                    $product = $this->pickProducts(1, $shownIds)[0];
                    $daysLeft = $now->diff($promoCode->getExpiresAt())->days;
                    $this->mailerService->sendPromoCodeReminderUpdate($promoCode, $product, $daysLeft);
                    $promoCode->setReminderUpdateCount($promoCode->getReminderUpdateCount() + 1);
                    $promoCode->setReminderLastProductIds((string) $product['id']);
                } else {
                    $product = $this->pickProducts(1, $shownIds)[0];
                    $this->mailerService->sendPromoCodeReminderUrgency($promoCode, $product);
                    $promoCode->setReminderUrgencySentAt($now);
                    $promoCode->setReminderLastProductIds((string) $product['id']);
                }

                $this->em->persist(new EmailSendLog($promoCode->getEmail(), 'promo_code_reminder_' . $action));

                $sentByAction[$action]++;
                $io->writeln("✅ {$promoCode->getEmail()} — {$promoCode->getCode()} ({$action})");
            } catch (\Throwable $e) {
                $this->logger->error('❌ Échec relance code promo', [
                    'email' => $promoCode->getEmail(),
                    'code' => $promoCode->getCode(),
                    'error' => $e->getMessage(),
                ]);
                $io->error("Échec pour {$promoCode->getEmail()} : " . $e->getMessage());
            }
        }

        $this->em->flush();

        $io->success(sprintf(
            "%d rappel(s), %d mise(s) à jour, %d relance(s) d'urgence envoyée(s).",
            $sentByAction['rappel'],
            $sentByAction['update'],
            $sentByAction['urgency'],
        ));

        return Command::SUCCESS;
    }

    /**
     * Nombre de codes qui recevraient une relance si la commande s'exécutait
     * maintenant. Utilisé pour l'aperçu admin (aucun email envoyé, aucune
     * écriture en base).
     */
    public function countPending(): int
    {
        $now = new \DateTimeImmutable();

        $count = 0;
        foreach ($this->getUnusedCodes() as $promoCode) {
            if (self::nextAction($promoCode, $now) !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return PromoCode[]
     */
    private function getUnusedCodes(): array
    {
        return $this->promoCodeRepository->createQueryBuilder('p')
            ->andWhere('p.type IN (:types)')
            ->andWhere('p.isUsed = false')
            ->andWhere('p.isActive = true')
            ->andWhere('p.expiresAt > :now')
            ->setParameter('types', self::TYPES)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    /**
     * @return 'rappel'|'update'|'urgency'|null
     */
    private static function nextAction(PromoCode $promoCode, \DateTimeImmutable $now): ?string
    {
        // getUnusedCodes() ne retourne que des codes dont expiresAt > now.
        $daysUntilExpiry = $now->diff($promoCode->getExpiresAt())->days;

        if ($promoCode->getReminderUrgencySentAt() === null && $daysUntilExpiry <= self::URGENCY_DAYS_BEFORE_EXPIRY) {
            return 'urgency';
        }

        $daysSinceCreation = $promoCode->getCreatedAt()->diff($now)->days;

        if ($promoCode->getReminderRappelSentAt() === null) {
            return $daysSinceCreation >= self::RAPPEL_MIN_AGE_DAYS ? 'rappel' : null;
        }

        $triggerDays = self::getUpdateTriggerDays($promoCode);
        $nextIndex = $promoCode->getReminderUpdateCount();

        if ($nextIndex < count($triggerDays) && $daysSinceCreation >= $triggerDays[$nextIndex]) {
            return 'update';
        }

        return null;
    }

    /**
     * Jours (depuis la création) auxquels envoyer une mise à jour mensuelle :
     * J+7 et J-3 de chaque tranche de 30 jours, à l'exclusion du dernier mois
     * avant expiration (qui reçoit l'urgence à la place). Un code de 30 jours
     * (launch) n'a qu'un seul mois → aucune mise à jour, comportement inchangé.
     *
     * @return int[]
     */
    private static function getUpdateTriggerDays(PromoCode $promoCode): array
    {
        $totalDays = $promoCode->getCreatedAt()->diff($promoCode->getExpiresAt())->days;
        $numMonths = intdiv($totalDays, self::MONTH_LENGTH_DAYS);

        $days = [];
        for ($month = 1; $month < $numMonths; $month++) {
            $days[] = ($month - 1) * self::MONTH_LENGTH_DAYS + 7;
            $days[] = ($month - 1) * self::MONTH_LENGTH_DAYS + (self::MONTH_LENGTH_DAYS - 3);
        }

        return $days;
    }

    /**
     * @return string[]
     */
    private static function parseProductIds(?string $stored): array
    {
        return $stored ? explode(',', $stored) : [];
    }

    /**
     * Best-sellers différents des produits déjà montrés à ce contact,
     * Kigelia en premier s'il est disponible.
     *
     * @param string[] $excludeIds
     * @return array{id:mixed,name:string,slug:string,price:string}[]
     */
    private function pickProducts(int $count, array $excludeIds): array
    {
        $products = $this->productRepository->getTopSelling(10);

        $available = array_values(array_filter(
            $products,
            fn(array $p) => !in_array((string) $p['id'], $excludeIds, true)
        ));
        if (count($available) < $count) {
            // Pas assez de produits distincts : on retombe sur la liste complète.
            $available = $products;
        }

        usort($available, fn(array $a, array $b) => str_contains(mb_strtolower($b['name']), 'kigelia') <=> str_contains(mb_strtolower($a['name']), 'kigelia'));

        return array_slice($available, 0, $count);
    }
}
