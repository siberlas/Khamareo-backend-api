<?php

namespace App\Marketing\DataFixtures;

use App\Marketing\Entity\NewsletterSubscriber;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;

class NewsletterSubscriberFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create();

        for ($i = 0; $i < 15; $i++) {
            $subscriber = new NewsletterSubscriber();
            $subscriber->setEmail($faker->unique()->safeEmail());

            $manager->persist($subscriber);
        }

        $manager->flush();
    }
}
