<?php

namespace App\DataFixtures;

use App\Entity\Product;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Faker\Factory;

class ProductFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');

        $allProducts = [];

        // Boucle sur les catégories existantes
        for ($i = 0; $i < CategoryFixtures::CATEGORY_COUNT; $i++) {
            /** @var \App\Entity\Category $category */
            $category = $this->getReference("category_$i", \App\Entity\Category::class);

            // Créer 10 produits par catégorie
            for ($j = 0; $j < 10; $j++) {
                $name = ucfirst($faker->words(3, true));

                $product = new Product();
                $product->setName($name)
                    ->setDescription($faker->paragraph(3))
                    ->setPrice($faker->randomFloat(2, 5, 200))
                    ->setOriginalPrice($faker->randomFloat(2, 20, 250))
                    ->setStock($faker->numberBetween(0, 100))
                    ->setWeight($faker->randomFloat(2, 0.1, 5))
                    ->setSlug(strtolower(str_replace(' ', '-', $name)) . '-' . $faker->unique()->numberBetween(1000, 9999))
                    ->setImageUrl('https://picsum.photos/400/300?random=' . rand(1, 100))
                    ->setImages([
                        'https://picsum.photos/400/300?random=' . rand(101, 200),
                        'https://picsum.photos/400/300?random=' . rand(201, 300),
                        'https://picsum.photos/400/300?random=' . rand(301, 400),
                    ])
                    ->setBadge($faker->randomElement(['Meilleure Vente', 'Nouveau', 'Promo']))
                    ->setCategory($category)
                    ->setRating($faker->randomFloat(1, 3, 5))
                    ->setReviewsCount($faker->numberBetween(10, 500))
                    ->setBenefits([
                        "Éclaircit et unifie le teint",
                        "Réduit les ridules et les rides",
                        "Stimule la production de collagène",
                        "Fournit une protection antioxydante",
                        "Hydrate et nourrit en profondeur",
                    ])
                    ->setIngredients("Aqua, Extrait de fruit, Glycérine, Vitamine C, Conservateurs Naturels")
                    ->setUsage("Appliquez 2-3 gouttes sur le visage matin et soir. Usage externe uniquement.");

                $manager->persist($product);
                $allProducts[] = $product;
            }
        }

        // 🔗 Associer des produits liés aléatoires (max 3)
        foreach ($allProducts as $product) {
            $related = $faker->randomElements($allProducts, rand(1, 3));
            foreach ($related as $rel) {
                if ($rel !== $product) {
                    $product->addRelatedProduct($rel);
                }
            }
            $manager->persist($product);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            CategoryFixtures::class,
        ];
    }
}
