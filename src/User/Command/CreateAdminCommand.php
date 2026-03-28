<?php

namespace App\User\Command;

use App\User\Entity\User;
use App\User\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-admin',
    description: 'Create an admin user',
)]
class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $userRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Admin email')
            ->addArgument('password', InputArgument::REQUIRED, 'Admin password')
            ->addArgument('firstname', InputArgument::OPTIONAL, 'First name', 'Admin')
            ->addArgument('lastname', InputArgument::OPTIONAL, 'Last name', 'Khamareo');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = $input->getArgument('email');

        $existing = $this->userRepo->findOneBy(['email' => $email]);
        if ($existing) {
            $output->writeln("<comment>User $email already exists — updating roles to ROLE_ADMIN.</comment>");
            $existing->setRoles(['ROLE_ADMIN', 'ROLE_USER']);
            $this->em->flush();
            return Command::SUCCESS;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setFirstName($input->getArgument('firstname'));
        $user->setLastName($input->getArgument('lastname'));
        $user->setRoles(['ROLE_ADMIN', 'ROLE_USER']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $input->getArgument('password')));

        $this->em->persist($user);
        $this->em->flush();

        $output->writeln("<info>Admin user $email created successfully.</info>");

        return Command::SUCCESS;
    }
}
