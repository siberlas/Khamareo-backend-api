<?php

namespace App\Scheduler\Service;

use App\Scheduler\Entity\CronJob;
use App\Scheduler\Enum\CronRunStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Exécute la commande Symfony sous-jacente d'un CronJob (planifiée ou déclenchée
 * manuellement depuis l'admin) et enregistre le résultat sur l'entité.
 */
class CronJobRunner
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    public function run(CronJob $job): void
    {
        $this->logger->info('⏰ Exécution cron job', [
            'key'     => $job->getKey(),
            'command' => $job->getCommandName(),
        ]);

        $bufferedOutput = new BufferedOutput();

        try {
            $application = new Application($this->kernel);
            $application->setAutoExit(false);

            $command = $application->find($job->getCommandName());
            $exitCode = $command->run(new ArrayInput([]), $bufferedOutput);

            $output = trim($bufferedOutput->fetch());

            $job->setLastRunAt(new \DateTimeImmutable());
            $job->setLastRunStatus($exitCode === 0 ? CronRunStatus::SUCCESS : CronRunStatus::ERROR);
            $job->setLastRunSummary(mb_substr($output, 0, 2000) ?: null);

            $this->logger->info('✅ Cron job terminé', [
                'key'    => $job->getKey(),
                'status' => $job->getLastRunStatus()->value,
            ]);
        } catch (\Throwable $e) {
            $job->setLastRunAt(new \DateTimeImmutable());
            $job->setLastRunStatus(CronRunStatus::ERROR);
            $job->setLastRunSummary('Exception : ' . $e->getMessage());

            $this->logger->error('❌ Échec cron job', [
                'key'   => $job->getKey(),
                'error' => $e->getMessage(),
            ]);
        }

        $this->em->flush();
    }
}
