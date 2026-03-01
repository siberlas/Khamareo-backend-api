<?php

namespace App\Cart\Command;

use App\Cart\Repository\CartRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cart:purge-guest',
    description: 'Supprime les paniers invités inactifs depuis plus de N jours (défaut : 30)'
)]
class PurgeGuestCartsCommand extends Command
{
    public function __construct(
        private CartRepository $cartRepository,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            'd',
            InputOption::VALUE_OPTIONAL,
            'Nombre de jours d\'inactivité avant suppression',
            30
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = (int) $input->getOption('days');

        if ($days < 1) {
            $io->error('Le nombre de jours doit être supérieur à 0.');
            return Command::FAILURE;
        }

        $threshold = new \DateTimeImmutable("-{$days} days");

        // Cherche tous les carts invités (guestToken non null) dont updatedAt < seuil
        // On cible les carts actifs ET inactifs pour nettoyer complètement
        $staleCarts = $this->cartRepository->createQueryBuilder('c')
            ->where('c.guestToken IS NOT NULL')
            ->andWhere('c.updatedAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();

        $count = count($staleCarts);

        if ($count === 0) {
            $io->success("Aucun panier invité inactif depuis plus de {$days} jours. Rien à supprimer.");
            return Command::SUCCESS;
        }

        $io->section("Suppression de {$count} panier(s) invité(s) inactifs depuis > {$days} jours");

        $deleted = 0;
        foreach ($staleCarts as $cart) {
            $this->em->remove($cart);
            $deleted++;

            // Flush par batch de 100 pour éviter de surcharger l'UoW
            if ($deleted % 100 === 0) {
                $this->em->flush();
                $this->em->clear();
                $io->writeln("  → {$deleted}/{$count} supprimés...");
            }
        }

        $this->em->flush();

        $io->success("{$deleted} panier(s) invité(s) supprimé(s) avec succès.");

        return Command::SUCCESS;
    }
}
