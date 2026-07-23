<?php

namespace App\Cart\Command;

use App\Cart\Entity\Cart;
use App\Cart\Repository\CartRepository;
use App\Catalog\Repository\ReviewRepository;
use App\Marketing\Entity\EmailSendLog;
use App\Marketing\Repository\PromoCodeRepository;
use App\Marketing\Service\PromoCodeService;
use App\Shared\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Segment 4 (phase 1) du cron marketing : séquence de relance panier abandonné
 * en 3 étapes (invités et connectés confondus, seul le contenu diffère) :
 *
 *   - Étape 1 (J+1h)  : rappel simple
 *   - Étape 2 (J+1j)  : réassurance (avis clients)
 *   - Étape 3 (J+3j)  : code -10% valable 48h (réutilise un code actif
 *                       existant du contact s'il y en a un, sinon en génère un)
 *
 * La fusion avec une relance Segment 3 du même jour n'est pas gérée dans
 * cette phase (cas rare, traité séparément).
 */
#[AsCommand(
    name: 'cart:reminder',
    description: 'Séquence de relance panier abandonné en 3 étapes (J+1h / J+1j / J+3j)'
)]
class CartReminderCommand extends Command
{
    private const STAGE1_MIN_INACTIVITY_SECONDS = 3600;       // 1h depuis updatedAt
    private const STAGE2_MIN_DELAY_SECONDS = 23 * 3600;       // +23h depuis l'étape 1 (≈ J+1j au total)
    private const STAGE3_MIN_DELAY_SECONDS = 48 * 3600;       // +48h depuis l'étape 2 (≈ J+3j au total)
    private const STAGE3_PROMO_VALIDITY_DAYS = 2;

    public function __construct(
        private CartRepository $cartRepository,
        private PromoCodeRepository $promoCodeRepository,
        private PromoCodeService $promoCodeService,
        private ReviewRepository $reviewRepository,
        private MailerService $mailerService,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();

        $sentByStage = [1 => 0, 2 => 0, 3 => 0];

        foreach ($this->getEligibleCarts() as $cart) {
            $action = self::nextAction($cart, $now);
            if ($action === null) {
                continue;
            }

            $owner = $cart->getOwner();
            $email = $owner?->getEmail();
            if (!$email) {
                continue;
            }

            try {
                $sent = match ($action) {
                    1 => $this->mailerService->sendCartReminderStage1($cart),
                    2 => $this->mailerService->sendCartReminderStage2($cart, $this->reviewRepository->findTopVerified(3)),
                    3 => $this->sendStage3($cart, $email),
                };

                if (!$sent) {
                    continue;
                }

                $cart->setReminderStage($action);
                $cart->setReminderStageLastSentAt($now);

                // Champs legacy conservés pour l'affichage admin existant
                // (page "Paniers abandonnés").
                if ($owner->isGuest()) {
                    $cart->setLastGuestReminderAt($now)->setGuestReminderCount($cart->getGuestReminderCount() + 1);
                } else {
                    $cart->setLastReminderAt($now)->setReminderCount($cart->getReminderCount() + 1);
                }

                $this->em->persist(new EmailSendLog($email, 'cart_reminder_stage' . $action));

                $sentByStage[$action]++;
                $io->writeln("✅ {$email} — panier {$cart->getId()} (étape {$action})");
            } catch (\Throwable $e) {
                $this->logger->error('❌ Échec relance panier', [
                    'cart_id' => (string) $cart->getId(),
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
                $io->error("Échec pour {$email} : " . $e->getMessage());
            }
        }

        $this->em->flush();

        $total = array_sum($sentByStage);
        $io->success("$total mail(s) envoyé(s) ({$sentByStage[1]} étape 1, {$sentByStage[2]} étape 2, {$sentByStage[3]} étape 3).");

        return Command::SUCCESS;
    }

    /**
     * Nombre de paniers qui seraient relancés si la commande s'exécutait
     * maintenant. Utilisé pour l'aperçu admin (aucun email envoyé, aucune
     * écriture en base).
     */
    public function countPending(): int
    {
        $now = new \DateTimeImmutable();

        $count = 0;
        foreach ($this->getEligibleCarts() as $cart) {
            if ($cart->getOwner()?->getEmail() && self::nextAction($cart, $now) !== null) {
                $count++;
            }
        }

        return $count;
    }

    private function sendStage3(Cart $cart, string $email): bool
    {
        $promoCode = $this->promoCodeRepository->findActiveUnusedByEmail($email);

        if ($promoCode === null) {
            $promoCode = $this->promoCodeService->createPromoCode(
                email: $email,
                type: 'cart_recovery',
                discountPercentage: 10.0,
                validityDays: self::STAGE3_PROMO_VALIDITY_DAYS,
            );
        }

        $sent = $this->mailerService->sendCartReminderStage3($cart, $promoCode);

        if ($sent) {
            $cart->setReminderPromoCodeId((string) $promoCode->getId());
        }

        return $sent;
    }

    /**
     * @return Cart[]
     */
    private function getEligibleCarts(): array
    {
        $carts = $this->cartRepository->createQueryBuilder('c')
            ->join('c.owner', 'u')
            ->andWhere('c.isActive = true')
            ->andWhere('c.items IS NOT EMPTY')
            ->andWhere('c.reminderStage < 3')
            ->getQuery()
            ->getResult();

        if (empty($carts)) {
            return [];
        }

        // Un panier "actif" en base peut être une simple session/guestToken
        // différente de celle utilisée pour commander (invité qui revient sur
        // un autre appareil, cookies effacés, etc.) — sans lien direct entre
        // les deux paniers. On exclut donc tout panier dont le client (même
        // email) a déjà commandé depuis la dernière activité de CE panier,
        // pour ne pas relancer quelqu'un qui vient de passer commande.
        $latestOrderByEmail = $this->latestOrderDateByEmail();

        return array_values(array_filter($carts, function (Cart $cart) use ($latestOrderByEmail) {
            $email = strtolower(trim($cart->getOwner()?->getEmail() ?? ''));
            if ($email === '' || !isset($latestOrderByEmail[$email])) {
                return true;
            }

            $reference = $cart->getUpdatedAt() ?? $cart->getCreatedAt();
            return $reference === null || $latestOrderByEmail[$email] < $reference;
        }));
    }

    /**
     * @return array<string, \DateTimeImmutable> email (minuscule) => date de la commande la plus récente
     */
    private function latestOrderDateByEmail(): array
    {
        $rows = $this->em->createQuery(
            'SELECT LOWER(COALESCE(u.email, o.guestEmail)) AS email, MAX(o.createdAt) AS lastOrderAt
             FROM App\Order\Entity\Order o
             LEFT JOIN o.owner u
             GROUP BY email'
        )->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            if ($row['email'] === null || $row['lastOrderAt'] === null) {
                continue;
            }
            $map[$row['email']] = $row['lastOrderAt'] instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($row['lastOrderAt'])
                : new \DateTimeImmutable($row['lastOrderAt']);
        }

        return $map;
    }

    /**
     * @return 1|2|3|null
     */
    private static function nextAction(Cart $cart, \DateTimeImmutable $now): ?int
    {
        $stage = $cart->getReminderStage();

        if ($stage === 0) {
            $reference = $cart->getUpdatedAt() ?? $cart->getCreatedAt();
            if ($reference !== null && ($now->getTimestamp() - $reference->getTimestamp()) >= self::STAGE1_MIN_INACTIVITY_SECONDS) {
                return 1;
            }
            return null;
        }

        $lastSent = $cart->getReminderStageLastSentAt();
        if ($lastSent === null) {
            return null;
        }

        $elapsed = $now->getTimestamp() - $lastSent->getTimestamp();

        if ($stage === 1 && $elapsed >= self::STAGE2_MIN_DELAY_SECONDS) {
            return 2;
        }

        if ($stage === 2 && $elapsed >= self::STAGE3_MIN_DELAY_SECONDS) {
            return 3;
        }

        return null;
    }
}
