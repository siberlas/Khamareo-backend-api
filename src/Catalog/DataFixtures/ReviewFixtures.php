<?php

namespace App\Catalog\DataFixtures;

use App\Catalog\Entity\Product;
use App\Catalog\Entity\Review;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Faker\Factory;

class ReviewFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');

        // Récupérer tous les produits créés par ProductFixtures
        $products = $manager->getRepository(Product::class)->findAll();

        foreach ($products as $product) {
            // Chaque produit reçoit entre 1 et 5 reviews
            $reviewCount = $faker->numberBetween(1, 5);

            for ($i = 0; $i < $reviewCount; $i++) {
                $review = new Review();
                $review->setName($faker->firstName . ' ' . strtoupper(substr($faker->lastName, 0, 1)) . '.');
                $review->setRating($faker->numberBetween(3, 5));
                $review->setComment($faker->realTextBetween(50, 150));
                $review->setProduct($product);

                $manager->persist($review);
            }
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            ProductFixtures::class,
        ];
    }
}
