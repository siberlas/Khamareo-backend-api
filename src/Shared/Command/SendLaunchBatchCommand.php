<?php

namespace App\Shared\Command;

use App\Shared\Entity\LaunchEmailQueue;
use App\Shared\Repository\AppSettingsRepository;
use App\Shared\Repository\LaunchEmailQueueRepository;
use App\Shared\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:send-launch-batch',
    description: 'Envoie un batch d\'emails de lancement (respecte la limite quotidienne Brevo)'
)]
class SendLaunchBatchCommand extends Command
{
    private const DEFAULT_DAILY_LIMIT = 290;

    public function __construct(
        private readonly LaunchEmailQueueRepository $queueRepo,
        private readonly AppSettingsRepository $settingsRepo,
        private readonly MailerService $mailerService,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Nombre max d\'emails à envoyer (override le setting)');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simuler sans envoyer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int) ($input->getOption('limit') ?: $this->getDailyLimit());
        $dryRun = $input->getOption('dry-run');

        $stats = $this->queueRepo->getStats();
        $output->writeln(sprintf(
            'Queue: %d total | %d pending | %d sent | %d failed',
            $stats['total'], $stats['pending'], $stats['sent'], $stats['failed']
        ));

        if ($stats['pending'] === 0) {
            $output->writeln('Aucun email en attente.');
            return Command::SUCCESS;
        }

        $batch = $this->queueRepo->findPendingBatch($limit);
        $output->writeln(sprintf('Envoi de %d emails%s...', count($batch), $dryRun ? ' (DRY RUN)' : ''));

        $sent = 0;
        $errors = 0;

        foreach ($batch as $item) {
            if ($dryRun) {
                $output->writeln(sprintf('  [DRY] %s → %s', $item->getEmail(), $item->getPromoCode()));
                ++$sent;
                continue;
            }

            $result = $this->mailerService->sendLaunchAnnouncement(
                $item->getEmail(),
                $item->getPromoCode(),
                10,
                $item->getLaunchDate()->modify('+30 days'),
                $item->getLaunchDate(),
                'fr',
            );

            if ($result['success']) {
                $item->markAsSent();
                ++$sent;
            } else {
                $errorMsg = $result['error'] ?? 'Erreur inconnue';
                $errorLower = strtolower($errorMsg);

                $isRateLimit = str_contains($errorLower, 'rate limit')
                    || str_contains($errorLower, 'quota')
                    || str_contains($errorLower, 'too many')
                    || str_contains($errorLower, '429')
                    || str_contains($errorLower, 'daily limit')
                    || str_contains($errorLower, 'sending limit');

                if ($isRateLimit) {
                    $output->writeln(sprintf('<error>Limite Brevo atteinte : %s</error>', $errorMsg));
                    $this->em->flush();
                    break;
                }

                $item->markAsFailed($errorMsg);
                ++$errors;
                $output->writeln(sprintf('  [ERREUR] %s : %s', $item->getEmail(), $errorMsg));
            }

            $this->em->flush();
        }

        $output->writeln(sprintf('Terminé : %d envoyés, %d erreurs.', $sent, $errors));

        $remaining = $this->queueRepo->countByStatus(LaunchEmailQueue::STATUS_PENDING);
        if ($remaining > 0) {
            $daysLeft = (int) ceil($remaining / $limit);
            $output->writeln(sprintf('Restant : %d emails → ~%d jour(s) d\'envoi.', $remaining, $daysLeft));
        } else {
            $output->writeln('Tous les emails de lancement ont été envoyés !');
        }

        $this->logger->info('Launch batch completed', [
            'sent' => $sent,
            'errors' => $errors,
            'remaining' => $remaining ?? 0,
        ]);

        return Command::SUCCESS;
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
