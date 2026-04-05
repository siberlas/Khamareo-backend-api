<?php

namespace App\Shared\Command;

use App\Shared\Entity\PreRegistration;
use App\Shared\Repository\PreRegistrationRepository;
use App\Shared\Service\MailchimpService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:import-mailchimp',
    description: 'Importe les abonnés Mailchimp dans la table pre_registration (dédupliqués)'
)]
class ImportMailchimpCommand extends Command
{
    public function __construct(
        private readonly MailchimpService $mailchimpService,
        private readonly PreRegistrationRepository $preRegRepo,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simuler sans insérer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = $input->getOption('dry-run');

        $output->writeln('Récupération des abonnés Mailchimp...');
        $emails = $this->mailchimpService->getSubscribedEmails();
        $output->writeln(sprintf('%d email(s) trouvé(s) dans Mailchimp.', count($emails)));

        if (count($emails) === 0) {
            $output->writeln('Aucun email à importer.');
            return Command::SUCCESS;
        }

        $imported = 0;
        $skipped = 0;

        foreach ($emails as $email) {
            $email = strtolower(trim($email));

            // Vérifier si déjà pré-inscrit
            if ($this->preRegRepo->findByEmail($email)) {
                ++$skipped;
                continue;
            }

            if ($dryRun) {
                $output->writeln(sprintf('  [DRY] %s', $email));
                ++$imported;
                continue;
            }

            $preReg = new PreRegistration();
            $preReg->setEmail($email);
            $preReg->setConsentGiven(true);
            $this->em->persist($preReg);
            ++$imported;

            if ($imported % 100 === 0) {
                $this->em->flush();
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $output->writeln(sprintf(
            'Terminé : %d importé(s), %d déjà existant(s)%s.',
            $imported,
            $skipped,
            $dryRun ? ' (DRY RUN)' : ''
        ));

        return Command::SUCCESS;
    }
}
