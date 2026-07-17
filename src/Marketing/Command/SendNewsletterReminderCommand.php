<?php

namespace App\Marketing\Command;

use App\Marketing\Entity\EmailSendLog;
use App\Marketing\Repository\NewsletterSubscriberRepository;
use App\Shared\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Segment 1 du cron marketing : relance hebdomadaire des abonnés newsletter
 * qui n'ont pas confirmé leur inscription (double opt-in), pendant 8 semaines
 * maximum. Après la 8e relance sans confirmation, on arrête l'envoi mais on
 * ne purge pas le contact.
 */
#[AsCommand(
    name: 'app:send-newsletter-reminder',
    description: 'Relance hebdomadaire des abonnés newsletter non confirmés (max 8 relances)'
)]
class SendNewsletterReminderCommand extends Command
{
    private const MAX_REMINDERS = 8;
    private const REMINDER_INTERVAL_DAYS = 7;

    public function __construct(
        private readonly NewsletterSubscriberRepository $repository,
        private readonly MailerService $mailerService,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly string $backendUrl,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();

        $subscribers = $this->repository->createQueryBuilder('n')
            ->andWhere('n.confirmedAt IS NULL')
            ->andWhere('n.reminderStopped = false')
            ->getQuery()
            ->getResult();

        $sent = 0;
        $stopped = 0;

        foreach ($subscribers as $subscriber) {
            if (!self::isDue($subscriber->getReminderLastSentAt(), $now)) {
                continue;
            }

            try {
                $token = bin2hex(random_bytes(32));
                $subscriber->setConfirmationToken($token);

                $confirmUrl     = $this->backendUrl . '/api/newsletter/confirm?token=' . $token;
                $unsubscribeUrl = $this->backendUrl . '/api/newsletter/unsubscribe?token=' . $token;

                $this->mailerService->sendNewsletterReminderEmail($subscriber, $confirmUrl, $unsubscribeUrl);
                $subscriber->setConfirmationSentAt($now);

                if ($subscriber->getReminderCount() === 0) {
                    $subscriber->setReminderFirstSentAt($now);
                }
                $newCount = $subscriber->getReminderCount() + 1;
                $subscriber->setReminderCount($newCount);
                $subscriber->setReminderLastSentAt($now);

                if ($newCount >= self::MAX_REMINDERS) {
                    $subscriber->setReminderStopped(true);
                    $stopped++;
                }

                $this->em->persist(new EmailSendLog($subscriber->getEmail(), 'newsletter_reminder'));

                $sent++;
                $io->writeln("✅ {$subscriber->getEmail()} — relance {$newCount}/" . self::MAX_REMINDERS);
            } catch (\Throwable $e) {
                $this->logger->error('❌ Échec relance newsletter', [
                    'email' => $subscriber->getEmail(),
                    'error' => $e->getMessage(),
                ]);
                $io->error("Échec pour {$subscriber->getEmail()} : " . $e->getMessage());
            }
        }

        $this->em->flush();

        $io->success("$sent relance(s) envoyée(s), $stopped arrêt(s) de séquence (8 relances atteintes).");

        return Command::SUCCESS;
    }

    /**
     * Nombre d'abonnés qui recevraient une relance si la commande s'exécutait maintenant.
     * Utilisé pour l'aperçu admin (aucun email envoyé, aucune écriture en base).
     */
    public function countPending(): int
    {
        $now = new \DateTimeImmutable();

        $subscribers = $this->repository->createQueryBuilder('n')
            ->andWhere('n.confirmedAt IS NULL')
            ->andWhere('n.reminderStopped = false')
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($subscribers as $subscriber) {
            if (self::isDue($subscriber->getReminderLastSentAt(), $now)) {
                $count++;
            }
        }

        return $count;
    }

    private static function isDue(?\DateTimeImmutable $lastSent, \DateTimeImmutable $now): bool
    {
        return $lastSent === null || $lastSent->diff($now)->days >= self::REMINDER_INTERVAL_DAYS;
    }
}
