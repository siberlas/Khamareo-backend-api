<?php

namespace App\Cart\Command;

use App\Cart\Repository\CartRepository;
use App\Shared\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'cart:reminder',
    description: 'Envoie des mails de rappel pour les paniers non validés (invités et connectés)'
)]
class CartReminderCommand extends Command
{
    public function __construct(
        private CartRepository $cartRepository,
        private MailerService $mailerService,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();
        $reminded = 0;
        $remindedGuests = 0;
        $remindedUsers = 0;

        // Rappels invités : hebdo + 3j avant suppression (30j)
        $guestCarts = $this->cartRepository->createQueryBuilder('c')
            ->join('c.owner', 'u')
            ->where('u.isGuest = true')
            ->andWhere('c.items IS NOT EMPTY')
            ->getQuery()->getResult();
        foreach ($guestCarts as $cart) {
            $created = $cart->getCreatedAt();
            $last = $cart->getLastGuestReminderAt();
            $days = $created ? $created->diff($now)->days : null;
            // 3j avant suppression, ou rappel hebdo sinon — sendAbandonedCartCheckoutRecovery()
            // gère déjà la mise à jour de lastGuestReminderAt/guestReminderCount et son propre flush.
            $shouldRemind = ($days !== null && $days >= 27 && $days < 30 && (!$last || $last->diff($now)->days >= 1))
                || ($days !== null && $days < 27 && (!$last || $last->diff($now)->days >= 7));

            if ($shouldRemind && $this->mailerService->sendAbandonedCartCheckoutRecovery($cart)) {
                $remindedGuests++;
            }
        }

        // Rappels utilisateurs connectés : tous les 10j, max 4 fois
        $userCarts = $this->cartRepository->createQueryBuilder('c')
            ->join('c.owner', 'u')
            ->where('u.isGuest = false')
            ->andWhere('c.items IS NOT EMPTY')
            ->getQuery()->getResult();
        foreach ($userCarts as $cart) {
            $last = $cart->getLastReminderAt();
            $count = $cart->getReminderCount();
            if ($count < 4 && (!$last || $last->diff($now)->days >= 10)) {
                if ($this->mailerService->sendAbandonedCartCheckoutRecovery($cart)) {
                    $remindedUsers++;
                }
            }
        }

        $this->em->flush();
        $reminded = $remindedGuests + $remindedUsers;
        $output->writeln("$reminded mails de rappel envoyés ($remindedGuests invités, $remindedUsers connectés)");
        return Command::SUCCESS;
    }
}
