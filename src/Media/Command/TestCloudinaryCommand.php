<?php

namespace App\Media\Command;

use App\Media\Service\CloudinaryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-cloudinary',
    description: 'Test complet du service Cloudinary',
)]
class TestCloudinaryCommand extends Command
{
    public function __construct(
        private CloudinaryService $cloudinary
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('🧪 Test Cloudinary Service V2 (Optimisé)');

        // Test 1 : Search API
        $io->section('📋 Test 1: Liste des images (Search API)');
        $result = $this->cloudinary->listImages('khamareo/blog', 50);

        if ($result['success']) {
            $io->success(sprintf('✅ %d image(s) trouvée(s)', $result['total']));
            
            if ($result['total'] > 0) {
                $io->writeln('Premières images :');
                foreach (array_slice($result['images'], 0, 5) as $image) {
                    $io->writeln(sprintf(
                        '  • %s',
                        $image['publicId']
                    ));
                    $io->writeln(sprintf(
                        '    Asset ID: %s',
                        $image['assetId']
                    ));
                    $io->writeln(sprintf(
                        '    Tags: %s',
                        implode(', ', $image['tags']) ?: 'aucun'
                    ));
                    $io->writeln('');
                }
            }
        } else {
            $io->error('❌ ' . $result['error']);
        }

        // Test 2 : Statistiques
        $io->section('📊 Test 2: Statistiques du dossier');
        $stats = $this->cloudinary->getFolderStats('khamareo/blog');

        if ($stats['success']) {
            $io->success('✅ Statistiques récupérées');
            $io->listing([
                sprintf('Total images : %d', $stats['stats']['totalImages']),
                sprintf('Taille totale : %.2f MB', $stats['stats']['totalSizeMB']),
                sprintf('Formats : %s', json_encode($stats['stats']['formats'])),
                sprintf('Tags : %s', json_encode($stats['stats']['tags'])),
            ]);
        }

        // Test 3 : Récupération par asset_id (si images existent)
        if ($result['success'] && $result['total'] > 0) {
            $io->section('📸 Test 3: Récupération par asset_id');
            $firstImage = $result['images'][0];
            $assetId = $firstImage['assetId'];
            
            $details = $this->cloudinary->getImageByAssetId($assetId);
            
            if ($details['success']) {
                $io->success('✅ Image récupérée par asset_id');
                $io->writeln(sprintf('Public ID: %s', $details['image']['publicId']));
                $io->writeln(sprintf('URL: %s', $details['image']['url']));
            }
        }

        $io->newLine();
        $io->success('🎉 Tests terminés !');

        return Command::SUCCESS;
    }
}