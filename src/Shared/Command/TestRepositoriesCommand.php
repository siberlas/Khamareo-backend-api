<?php

namespace App\Shared\Command;

use App\Blog\Repository\BlogPostRepository;
use App\Blog\Repository\BlogCategoryRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-repositories',
    description: 'Test des méthodes des repositories',
)]
class TestRepositoriesCommand extends Command
{
    public function __construct(
        private BlogPostRepository $blogPostRepository,
        private BlogCategoryRepository $blogCategoryRepository
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('🧪 Test des Repositories');

        // Test 1 : Articles publiés
        $io->section('📋 Articles publiés');
        $published = $this->blogPostRepository->findPublished(5);
        $io->success(sprintf('%d article(s) publié(s)', count($published)));
        foreach ($published as $post) {
            $io->writeln(sprintf('  • %s', $post->getTitle()));
        }

        // Test 2 : Articles à la une
        $io->section('⭐ Articles à la une');
        $featured = $this->blogPostRepository->findFeatured(3);
        $io->success(sprintf('%d article(s) à la une', count($featured)));
        foreach ($featured as $post) {
            $io->writeln(sprintf('  • %s', $post->getTitle()));
        }

        // Test 3 : Statistiques
        $io->section('📊 Statistiques');
        $stats = $this->blogPostRepository->getStatistics();
        $io->listing([
            sprintf('Total publié : %d', $stats['totalPublished']),
            sprintf('Total brouillons : %d', $stats['totalDrafts']),
            sprintf('Total à la une : %d', $stats['totalFeatured']),
            sprintf('Dernier publié : %s', $stats['lastPublishedAt']?->format('d/m/Y H:i') ?? 'aucun'),
        ]);
https://claude.ai/chat/d041cb6a-6832-45c6-a611-2219d9e68338
        // Test 4 : Catégories avec nombre d'articles
        $io->section('🏷️ Catégories');
        $categories = $this->blogCategoryRepository->findAllWithPostCount();
        $io->success(sprintf('%d catégorie(s)', count($categories)));
        foreach ($categories as $result) {
            $category = $result[0];
            $count = $result['postCount'];
            $io->writeln(sprintf('  • %s (%d articles)', $category->getName(), $count));
        }

        $io->success('✅ Tests terminés !');

        return Command::SUCCESS;
    }
}