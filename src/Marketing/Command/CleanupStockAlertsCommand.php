<?php

namespace App\Marketing\Command;

use App\Marketing\Repository\StockAlertRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup-stock-alerts',
    description: 'Supprimer les alertes notifiées de plus de 30 jours',
)]
class CleanupStockAlertsCommand extends Command
{
    public function __construct(
        private StockAlertRepository $alertRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            'd',
            InputOption::VALUE_OPTIONAL,
            'Nombre de jours',
            30
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = (int) $input->getOption('days');

        $io->title('Nettoyage des alertes stock');

        $deleted = $this->alertRepository->deleteOldNotifiedAlerts($days);

        if ($deleted === 0) {
            $io->success('Aucune alerte à nettoyer');
        } else {
            $io->success(sprintf('%d alerte(s) supprimée(s)', $deleted));
        }

        return Command::SUCCESS;
    }
}