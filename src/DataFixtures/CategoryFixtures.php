<?php

namespace App\DataFixtures;

use App\Entity\Category;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;

class CategoryFixtures extends Fixture
{
    public const CATEGORY_COUNT = 5;
    public const SUBCATEGORY_COUNT = 3; // nb de sous-catégories par catégorie

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create();

        for ($i = 0; $i < self::CATEGORY_COUNT; $i++) {
            // Création d'une catégorie principale
            $parentName = ucfirst($faker->unique()->word());

            $parentCategory = new Category();
            $parentCategory->setName($parentName)
                           ->setSlug(strtolower($parentName))
                           ->setDescription($faker->sentence(10));

            $manager->persist($parentCategory);
            $this->addReference("category_$i", $parentCategory);

            // Création des sous-catégories
            for ($j = 0; $j < self::SUBCATEGORY_COUNT; $j++) {
                $childName = ucfirst($faker->unique()->word());

                $childCategory = new Category();
                $childCategory->setName($childName)
                              ->setSlug(strtolower($childName))
                              ->setDescription($faker->sentence(8))
                              ->setParent($parentCategory);

                $manager->persist($childCategory);
                $this->addReference("subcategory_{$i}_{$j}", $childCategory);
            }
        }

        $manager->flush();
    }
}
