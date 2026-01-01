<?php
// src/Command/MigrateProductImagesToMediaCommand.php

namespace App\Media\Command;

use App\Media\Entity\Media;
use App\Catalog\Entity\Product;
use App\Media\Entity\ProductMedia;
use App\Media\Service\MediaService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:migrate-product-images',
    description: 'Migre les images des produits vers le système Media centralisé',
)]
class MigrateProductImagesToMediaCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private MediaService $mediaService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simuler la migration sans sauvegarder')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limiter le nombre de produits', 0)
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forcer la migration même si des images existent déjà');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $dryRun = $input->getOption('dry-run');
        $limit = (int) $input->getOption('limit');
        $force = $input->getOption('force');

        $io->title('Migration des images produits vers Media Library');

        if ($dryRun) {
            $io->warning('Mode DRY-RUN activé - Aucune modification ne sera sauvegardée');
        }

        // 1. Récupérer tous les produits
        $qb = $this->em->getRepository(Product::class)->createQueryBuilder('p');
        
        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        $products = $qb->getQuery()->getResult();
        $totalProducts = count($products);

        $io->info("Produits à traiter : {$totalProducts}");
        $io->newLine();

        // Statistiques
        $stats = [
            'products_processed' => 0,
            'images_migrated' => 0,
            'images_skipped' => 0,
            'errors' => 0,
        ];

        $io->progressStart($totalProducts);

        foreach ($products as $product) {
            $stats['products_processed']++;

            // Vérifier si le produit a déjà des ProductMedia
            $existingMediaCount = $this->em->getRepository(ProductMedia::class)
                ->count(['product' => $product]);

            if ($existingMediaCount > 0 && !$force) {
                $io->text("⏭️  Produit '{$product->getName()}' déjà migré ({$existingMediaCount} images) - Ignoré");
                $stats['images_skipped'] += $existingMediaCount;
                $io->progressAdvance();
                continue;
            }

            // Récupérer les URLs d'images existantes
            $imageUrls = $this->getProductImageUrls($product);

            if (empty($imageUrls)) {
                $io->text("⚠️  Produit '{$product->getName()}' n'a aucune image - Ignoré");
                $io->progressAdvance();
                continue;
            }

            $io->text("🔄 Migration de '{$product->getName()}' ({$product->getSlug()}) - " . count($imageUrls) . " images");

            foreach ($imageUrls as $index => $imageUrl) {
                try {
                    // Uploader l'image vers Cloudinary et créer Media
                    if (!$dryRun) {
                        $media = $this->mediaService->uploadFromUrl(
                            $imageUrl,
                            'khamareo/products',
                            ['product', $product->getSlug()],
                            "{$product->getName()} - Image " . ($index + 1)
                        );

                        // Créer la liaison ProductMedia
                        $productMedia = new ProductMedia();
                        $productMedia->setProduct($product);
                        $productMedia->setMedia($media);
                        $productMedia->setDisplayOrder($index);
                        $productMedia->setIsPrimary($index === 0);

                        $this->em->persist($productMedia);
                        
                        $io->text("  ✅ Image {$index}: {$imageUrl}");
                    } else {
                        $io->text("  [DRY-RUN] Image {$index}: {$imageUrl}");
                    }

                    $stats['images_migrated']++;

                } catch (\Exception $e) {
                    $io->error("  ❌ Erreur image {$index}: {$e->getMessage()}");
                    $stats['errors']++;
                }
            }

            // ✅ Flush après chaque produit (plus sûr)
            if (!$dryRun) {
                $this->em->flush();
                // ❌ NE PAS APPELER clear() ici
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        // Flush final (au cas où)
        if (!$dryRun) {
            $this->em->flush();
        }

        // Afficher les statistiques
        $io->newLine(2);
        $io->success('Migration terminée !');
        
        $io->table(
            ['Métrique', 'Valeur'],
            [
                ['Produits traités', $stats['products_processed']],
                ['Images migrées', $stats['images_migrated']],
                ['Images ignorées', $stats['images_skipped']],
                ['Erreurs', $stats['errors']],
            ]
        );

        if ($dryRun) {
            $io->note('Mode DRY-RUN : Aucune donnée n\'a été modifiée. Relancez sans --dry-run pour migrer réellement.');
        }

        return Command::SUCCESS;
    }

    /**
     * Récupère les URLs d'images d'un produit
     */
    private function getProductImageUrls(Product $product): array
    {
        $urls = [];

        // 1. Image principale (imageUrl)
        if ($product->getImageUrl()) {
            $urls[] = $product->getImageUrl();
        }

        // 2. Images additionnelles (champ JSON images)
        $images = $product->getImages();
        if (is_array($images)) {
            foreach ($images as $image) {
                // Si c'est un tableau avec 'url'
                if (is_array($image) && isset($image['url'])) {
                    $urls[] = $image['url'];
                }
                // Si c'est directement une URL
                elseif (is_string($image)) {
                    $urls[] = $image;
                }
            }
        }

        // Supprimer les doublons
        return array_unique($urls);
    }
}