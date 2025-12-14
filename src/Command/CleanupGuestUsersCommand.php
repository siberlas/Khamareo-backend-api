<?php

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup-guests',
    description: 'Nettoyer les utilisateurs invités expirés sans commande',
)]
class CleanupGuestUsersCommand extends Command
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Simuler le nettoyage sans supprimer'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isDryRun = $input->getOption('dry-run');

        $io->title('Nettoyage des utilisateurs invités expirés');

        if ($isDryRun) {
            $io->warning('Mode DRY-RUN activé - Aucune suppression ne sera effectuée');
        }

        // 1️⃣ Trouver les invités expirés sans commande
        $qb = $this->em->createQueryBuilder();
        $qb->select('u')
           ->from('App\Entity\User', 'u')
           ->where('u.isGuest = true')
           ->andWhere('u.guestExpiresAt < :now')
           ->andWhere('NOT EXISTS (
               SELECT 1 FROM App\Entity\Order o WHERE o.owner = u
           )')
           ->setParameter('now', new \DateTimeImmutable());

        $expiredGuests = $qb->getQuery()->getResult();
        $count = count($expiredGuests);

        if ($count === 0) {
            $io->success('Aucun invité expiré à nettoyer');
            return Command::SUCCESS;
        }

        $io->section("Invités expirés sans commande : $count");

        // Afficher les détails
        $rows = [];
        foreach ($expiredGuests as $guest) {
            $rows[] = [
                $guest->getId(),
                $guest->getEmail(),
                $guest->getGuestExpiresAt()?->format('Y-m-d H:i:s'),
                $guest->getCreatedAt()?->format('Y-m-d H:i:s'),
            ];
        }

        $io->table(
            ['ID', 'Email', 'Expiré le', 'Créé le'],
            $rows
        );

        if (!$isDryRun) {
            if (!$io->confirm("Confirmer la suppression de $count invités ?", false)) {
                $io->warning('Nettoyage annulé');
                return Command::SUCCESS;
            }

            // Supprimer
            foreach ($expiredGuests as $guest) {
                $this->em->remove($guest);
            }

            $this->em->flush();

            $io->success("$count invités expirés ont été supprimés");
        } else {
            $io->info("$count invités seraient supprimés (dry-run)");
        }

        // 2️⃣ Statistiques sur les invités actifs
        $activeGuestsCount = $this->userRepository->count([
            'isGuest' => true
        ]);

        $io->section('Statistiques');
        $io->listing([
            "Invités actifs : $activeGuestsCount",
            "Invités supprimés : " . ($isDryRun ? 0 : $count),
        ]);

        return Command::SUCCESS;
    }
}