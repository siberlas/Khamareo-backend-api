<?php
namespace App\Marketing\Command;

use App\Marketing\Repository\StockAlertRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'stock-alert:cleanup',
    description: 'Supprime les alertes stock notifiées depuis plus de 30 jours.'
)]
class StockAlertCleanupCommand extends Command
{
    public function __construct(private StockAlertRepository $repository)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = $this->repository->deleteOldNotifiedAlerts(30);
        $output->writeln("$deleted alertes supprimées.");
        return Command::SUCCESS;
    }
}
