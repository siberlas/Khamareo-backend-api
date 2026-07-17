<?php

namespace App\Scheduler\Command;

use App\Scheduler\Repository\CronJobRepository;
use App\Scheduler\Service\CronJobRunner;
use Cron\CronExpression;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Point d'entrée cron unique (une seule ligne crontab système) : exécute chaque
 * CronJob activé dont l'expression cron est due, sans jamais toucher au crontab
 * pour ajouter/retirer un job — tout se pilote depuis l'admin (table cron_job).
 */
#[AsCommand(
    name: 'app:cron-dispatch',
    description: 'Exécute les cron jobs activés dont la planification est due'
)]
class CronDispatchCommand extends Command
{
    public function __construct(
        private readonly CronJobRepository $cronJobRepository,
        private readonly CronJobRunner $runner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();

        $jobs = $this->cronJobRepository->findBy(['enabled' => true]);

        $ran = 0;
        foreach ($jobs as $job) {
            $due = new CronExpression($job->getCronExpression());
            if (!$due->isDue($now)) {
                continue;
            }

            $io->section("Exécution : {$job->getLabel()} ({$job->getCommandName()})");
            $this->runner->run($job);
            $ran++;
        }

        $io->success($ran > 0 ? "$ran job(s) exécuté(s)." : 'Aucun job dû pour le moment.');

        return Command::SUCCESS;
    }
}
