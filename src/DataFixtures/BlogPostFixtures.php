<?php

namespace App\DataFixtures;

use App\Entity\BlogPost;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Faker\Factory;

class BlogPostFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create();

        $users = $manager->getRepository(User::class)->findAll();

        foreach ($users as $user) {
            for ($i = 0; $i < 3; $i++) {
                $post = new BlogPost();
                $post->setTitle($faker->sentence(6))
                     ->setContent($faker->paragraphs(4, true))
                     ->setAuthor($user);

                $manager->persist($post);
            }
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
        ];
    }
}
