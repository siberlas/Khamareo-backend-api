<?php

namespace App\Media\Command;

use App\Media\Service\CloudinaryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-cloudinary-connection',
    description: 'Test la connexion Cloudinary et affiche les détails',
)]
class TestCloudinaryConnectionCommand extends Command
{
    public function __construct(
        private CloudinaryService $cloudinary,
        private string $cloudName,
        private string $apiKey
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('🔌 Test de connexion Cloudinary');

        // Afficher les credentials (masquer le secret)
        $io->section('🔑 Credentials configurés');
        $io->listing([
            sprintf('Cloud name: %s', $this->cloudName),
            sprintf('API Key: %s', $this->apiKey),
            sprintf('API Secret: %s', str_repeat('*', 20) . ' (masqué)'),
        ]);

        // Test 1 : Lister TOUS les assets (sans filter)
        $io->section('📋 Test 1: Lister tous les assets');
        try {
            $result = $this->cloudinary->listImages(''); // Prefix vide = tout
            
            if ($result['success']) {
                $io->success(sprintf('✅ Connexion OK - %d image(s) trouvée(s)', $result['total']));
                
                if ($result['total'] > 0) {
                    $io->writeln('Premiers résultats :');
                    foreach (array_slice($result['images'], 0, 5) as $image) {
                        $io->writeln(sprintf('  • %s', $image['publicId']));
                    }
                }
            } else {
                $io->error('❌ Erreur : ' . $result['error']);
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $io->error('❌ Exception : ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Test 2 : Lister avec le prefix "blog"
        $io->section('📋 Test 2: Lister avec prefix "blog"');
        $result = $this->cloudinary->listImages('blog');
        
        if ($result['success']) {
            $io->success(sprintf('✅ %d image(s) dans "blog"', $result['total']));
            
            foreach ($result['images'] as $image) {
                $io->writeln(sprintf('  • %s', $image['publicId']));
            }
        } else {
            $io->warning('⚠️ Aucune image avec prefix "blog"');
        }

        // Test 3 : Lister avec le prefix "khamareo/blog"
        $io->section('📋 Test 3: Lister avec prefix "khamareo/blog"');
        $result = $this->cloudinary->listImages('khamareo/blog');
        
        if ($result['success']) {
            $io->success(sprintf('✅ %d image(s) dans "khamareo/blog"', $result['total']));
            
            foreach ($result['images'] as $image) {
                $io->writeln(sprintf('  • %s', $image['publicId']));
            }
        } else {
            $io->warning('⚠️ Aucune image avec prefix "khamareo/blog"');
        }

        $result =  $this->cloudinary->getRootFolder();
          $io->success(sprintf('✅ total des dossiers %d "',$result['total_count']));
        foreach($result['folders'] as $folder) {
                $io->success(sprintf('✅ %s "',$folder['name']));
                 $io->success(sprintf('✅ %s "',$folder['path']));
                  $io->success(sprintf('✅ %s "',$folder['external_id']));
        }
      
        

        return Command::SUCCESS;
    }
}