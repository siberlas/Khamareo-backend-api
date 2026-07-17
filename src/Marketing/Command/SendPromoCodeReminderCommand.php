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
 * Segment 3 (phase 1) du cron marketing : relance des codes promo "launch"
 * (AKWAABA-XXXXXXXX, 30 jours) jamais utilisés.
 *
 * Cascade : un rappel neutre (Email 1) à J+3 après création du code, puis un
 * email d'urgence (Email 2) à J-3 avant expiration. Les codes déjà anciens au
 * premier lancement de cette commande reçoivent immédiatement l'Email 1
 * (rattrapage naturel, pas de script séparé nécessaire).
 *
 * Les autres types de codes (first_order/newsletter/registration, cascade
 * mensuelle) sont hors périmètre de cette phase.
 */
#[AsCommand(
    name: 'app:send-promo-code-reminder',
    description: 'Relance des codes promo "launch" non utilisés (rappel + urgence J-3)'
)]
class SendPromoCodeReminderCommand extends Command
{
    private const RAPPEL_MIN_AGE_DAYS = 3;
    private const URGENCY_DAYS_BEFORE_EXPIRY = 3;

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

        $rappelSent = 0;
        $urgencySent = 0;

        foreach ($this->getUnusedLaunchCodes() as $promoCode) {
            $action = self::nextAction($promoCode, $now);
            if ($action === null) {
                continue;
            }

            try {
                $shownIds = self::parseProductIds($promoCode->getReminderLastProductIds());

                if ($action === 'urgency') {
                    $product = $this->pickProducts(1, $shownIds)[0];
                    $this->mailerService->sendPromoCodeReminderUrgency($promoCode, $product);
                    $promoCode->setReminderUrgencySentAt($now);
                    $promoCode->setReminderLastProductIds((string) $product['id']);
                    $urgencySent++;
                } else {
                    $products = $this->pickProducts(3, $shownIds);
                    $this->mailerService->sendPromoCodeReminderRappel($promoCode, $products);
                    $promoCode->setReminderRappelSentAt($now);
                    $promoCode->setReminderLastProductIds(implode(',', array_map(fn(array $p) => (string) $p['id'], $products)));
                    $rappelSent++;
                }

                $this->em->persist(new EmailSendLog($promoCode->getEmail(), 'promo_code_reminder_' . $action));

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

        $io->success("$rappelSent rappel(s) envoyé(s), $urgencySent relance(s) d'urgence envoyée(s).");

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
        foreach ($this->getUnusedLaunchCodes() as $promoCode) {
            if (self::nextAction($promoCode, $now) !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return PromoCode[]
     */
    private function getUnusedLaunchCodes(): array
    {
        return $this->promoCodeRepository->createQueryBuilder('p')
            ->andWhere('p.type = :type')
            ->andWhere('p.isUsed = false')
            ->andWhere('p.isActive = true')
            ->andWhere('p.expiresAt > :now')
            ->setParameter('type', 'launch')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    /**
     * @return 'rappel'|'urgency'|null
     */
    private static function nextAction(PromoCode $promoCode, \DateTimeImmutable $now): ?string
    {
        // getUnusedLaunchCodes() ne retourne que des codes dont expiresAt > now.
        $daysUntilExpiry = $now->diff($promoCode->getExpiresAt())->days;

        if ($promoCode->getReminderUrgencySentAt() === null && $daysUntilExpiry <= self::URGENCY_DAYS_BEFORE_EXPIRY) {
            return 'urgency';
        }

        if ($promoCode->getReminderRappelSentAt() === null) {
            $daysSinceCreation = $promoCode->getCreatedAt()->diff($now)->days;
            if ($daysSinceCreation >= self::RAPPEL_MIN_AGE_DAYS) {
                return 'rappel';
            }
        }

        return null;
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
