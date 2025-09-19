<?php

namespace App\DataFixtures;

use App\Entity\Category;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;

class CategoryFixtures extends Fixture
{
    public const CATEGORY_COUNT = 5;

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create();

        for ($i = 0; $i < self::CATEGORY_COUNT; $i++) {
            $name = ucfirst($faker->unique()->word());

            $category = new Category();
            $category->setName($name)
                     ->setSlug(strtolower($name)) // slug basé sur le nom
                     ->setDescription($faker->sentence(10));

            $manager->persist($category);

            // Référence pour ProductFixtures
            $this->addReference("category_$i", $category);
        }

        $manager->flush();
    }
}
