<?php

namespace App\User\DataFixtures;

use App\User\Entity\Address;
use App\User\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Faker\Factory;

class AddressFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');

        $users = $manager->getRepository(User::class)->findAll();

        foreach ($users as $user) {

            // =======================
            // Adresse personnelle (1)
            // =======================
            $address1 = (new Address())
                ->setOwner($user)
                ->setAddressKind('personal')
                ->setIsBusiness(false)
                ->setCivility($faker->randomElement(['Monsieur', 'Madame']))
                ->setFirstName($faker->firstName)
                ->setLastName($faker->lastName)
                ->setStreetAddress($faker->streetAddress)
                ->setCity($faker->city)
                ->setPostalCode($faker->postcode)
                ->setCountry('FR')
                ->setPhone($faker->phoneNumber)
                ->setLabel('Adresse principale')
                ->setIsDefault(true);

            $manager->persist($address1);


            // =======================
            // Adresse personnelle (2)
            // =======================
            $address2 = (new Address())
                ->setOwner($user)
                ->setAddressKind('personal')
                ->setIsBusiness(false)
                ->setCivility($faker->randomElement(['Monsieur', 'Madame']))
                ->setFirstName($faker->firstName)
                ->setLastName($faker->lastName)
                ->setStreetAddress($faker->streetAddress)
                ->setCity($faker->city)
                ->setPostalCode($faker->postcode)
                ->setCountry('FR')
                ->setPhone($faker->phoneNumber)
                ->setLabel('Adresse secondaire')
                ->setIsDefault(false);

            $manager->persist($address2);


            // =======================
            // Adresse professionnelle
            // =======================
            $business = (new Address())
                ->setOwner($user)
                ->setAddressKind('business')
                ->setIsBusiness(true)
                ->setCompanyName($faker->company)
                ->setStreetAddress($faker->streetAddress)
                ->setCity($faker->city)
                ->setPostalCode($faker->postcode)
                ->setCountry('FR')
                ->setLabel('Adresse professionnelle')
                ->setIsDefault(false);

            $manager->persist($business);


            // =======================
            // Adresse point relais (cleanup)
            // =======================
            // OPTIONNEL : si tu veux générer des points relais dans tes fixtures
            // Pour l’instant → on ne génère pas, car ils ne sont pas utilisés pour les users réels.
            /*
            $relay = (new Address())
                ->setOwner($user)
                ->setAddressKind('relay')
                ->setIsRelayPoint(true)
                ->setRelayPointId('PR-' . bin2hex(random_bytes(3)))
                ->setRelayCarrier('MONDIAL_RELAY')
                ->setStreetAddress($faker->streetAddress)
                ->setCity($faker->city)
                ->setPostalCode($faker->postcode)
                ->setCountry('FR')
                ->setLabel('Point relais test')
                ->setIsDefault(false);

            $manager->persist($relay);
            */
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
