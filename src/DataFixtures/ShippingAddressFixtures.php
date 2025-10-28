<?php

namespace App\DataFixtures;

use App\Entity\ShippingAddress;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Faker\Factory;

class ShippingAddressFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');
        $users = $manager->getRepository(User::class)->findAll();

        foreach ($users as $user) {
            // Crée 2 adresses pour chaque utilisateur
            for ($i = 0; $i < 2; $i++) {
                $address = new ShippingAddress();
                $address->setOwner($user)
                        ->setFirstName($faker->firstName)
                        ->setLastName($faker->lastName)
                        ->setStreetAddress($faker->streetAddress)
                        ->setCity($faker->city)
                        ->setPostalCode($faker->postcode)
                        ->setCountry('France')
                        ->setPhone($faker->phoneNumber);

                $manager->persist($address);
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
