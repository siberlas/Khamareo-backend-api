<?php

namespace App\Marketing\Command;

use App\Marketing\Repository\StockAlertRepository;
use App\Shared\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:notify-stock-alerts',
    description: 'Notifier les utilisateurs quand les produits sont de nouveau en stock',
)]
class NotifyStockAlertsCommand extends Command
{
    public function __construct(
        private StockAlertRepository $alertRepository,
        private MailerService $mailerService,
        private EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Récupérer les alertes non notifiées pour produits en stock
        $alerts = $this->alertRepository->createQueryBuilder('a')
            ->join('a.product', 'p')
            ->where('a.notified = false')
            ->andWhere('p.stock > 0')
            ->getQuery()
            ->getResult();

        $count = count($alerts);

        if ($count === 0) {
            $io->success('Aucune alerte à notifier');
            return Command::SUCCESS;
        }

        $io->section("Envoi de $count notifications");

        foreach ($alerts as $alert) {
            try {
                $this->mailerService->sendStockAlertNotification($alert);
                
                $alert->setNotified(true);
                $alert->setNotifiedAt(new \DateTimeImmutable());
                
                $io->writeln("✅ " . $alert->getEmail() . " - " . $alert->getProduct()->getName());
            } catch (\Exception $e) {
                $io->error("❌ Erreur: " . $e->getMessage());
            }
        }

        $this->em->flush();

        $io->success("$count notifications envoyées");

        return Command::SUCCESS;
    }
}