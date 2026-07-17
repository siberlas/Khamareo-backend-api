<?php

namespace App\User\Command;

use App\Marketing\Entity\EmailSendLog;
use App\User\Entity\User;
use App\User\Repository\UserRepository;
use App\Shared\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Relance hebdomadaire des comptes clients créés mais dont l'email n'a
 * jamais été confirmé, pendant 8 semaines maximum. Après la 8e relance
 * sans confirmation, on arrête l'envoi mais on ne supprime pas le compte.
 */
#[AsCommand(
    name: 'app:send-verification-reminder',
    description: 'Relance hebdomadaire des comptes clients non vérifiés (max 8 relances)'
)]
class SendVerificationReminderCommand extends Command
{
    private const MAX_REMINDERS = 8;
    private const REMINDER_INTERVAL_DAYS = 7;

    public function __construct(
        private readonly UserRepository $userRepository,
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

        $users = $this->getUnverifiedUsers();

        $sent = 0;
        $stopped = 0;

        foreach ($users as $user) {
            if (!self::isDue($user->getVerificationReminderLastSentAt(), $now)) {
                continue;
            }

            try {
                // Un nouveau lien à chaque relance : l'ancien token reste valide
                // tant qu'il n'est pas remplacé (pas d'expiration en base).
                $user->setConfirmationToken(Uuid::v4()->toRfc4122());

                $this->mailerService->sendEmailConfirmation($user, $user->getConfirmationToken(), $user->isNewsletter());

                if ($user->getVerificationReminderCount() === 0) {
                    $user->setVerificationReminderFirstSentAt($now);
                }
                $newCount = $user->getVerificationReminderCount() + 1;
                $user->setVerificationReminderCount($newCount);
                $user->setVerificationReminderLastSentAt($now);

                if ($newCount >= self::MAX_REMINDERS) {
                    $user->setVerificationReminderStopped(true);
                    $stopped++;
                }

                $this->em->persist(new EmailSendLog($user->getEmail(), 'verification_reminder'));

                $sent++;
                $io->writeln("✅ {$user->getEmail()} — relance {$newCount}/" . self::MAX_REMINDERS);
            } catch (\Throwable $e) {
                $this->logger->error('❌ Échec relance vérification compte', [
                    'email' => $user->getEmail(),
                    'error' => $e->getMessage(),
                ]);
                $io->error("Échec pour {$user->getEmail()} : " . $e->getMessage());
            }
        }

        $this->em->flush();

        $io->success("$sent relance(s) envoyée(s), $stopped arrêt(s) de séquence (8 relances atteintes).");

        return Command::SUCCESS;
    }

    /**
     * Nombre de comptes qui recevraient une relance si la commande s'exécutait
     * maintenant. Utilisé pour l'aperçu admin (aucun email envoyé, aucune
     * écriture en base).
     */
    public function countPending(): int
    {
        $now = new \DateTimeImmutable();

        $count = 0;
        foreach ($this->getUnverifiedUsers() as $user) {
            if (self::isDue($user->getVerificationReminderLastSentAt(), $now)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return User[]
     */
    private function getUnverifiedUsers(): array
    {
        return $this->userRepository->createQueryBuilder('u')
            ->andWhere('u.isVerified = false')
            ->andWhere('u.isGuest = false')
            ->andWhere('u.verificationReminderStopped = false')
            ->getQuery()
            ->getResult();
    }

    private static function isDue(?\DateTimeImmutable $lastSent, \DateTimeImmutable $now): bool
    {
        return $lastSent === null || $lastSent->diff($now)->days >= self::REMINDER_INTERVAL_DAYS;
    }
}
