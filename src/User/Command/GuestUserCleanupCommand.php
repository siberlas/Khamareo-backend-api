<?php

namespace App\User\Command;

use App\User\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'user:guest-cleanup',
    description: 'Supprime les utilisateurs invités et leur panier après 30 jours.'
)]
class GuestUserCleanupCommand extends Command
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dateLimit = new \DateTimeImmutable('-30 days');
        $guests = $this->userRepository->createQueryBuilder('u')
            ->where('u.isGuest = true')
            ->andWhere('u.createdAt < :dateLimit')
            ->setParameter('dateLimit', $dateLimit)
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($guests as $guest) {
            // Suppression du panier
            foreach ($guest->getCarts() as $cart) {
                $this->em->remove($cart);
            }
            $this->em->remove($guest);
            $count++;
        }
        $this->em->flush();
        $output->writeln("$count utilisateurs invités et leurs paniers supprimés.");
        return Command::SUCCESS;
    }
}
