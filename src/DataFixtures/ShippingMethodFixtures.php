<?php

namespace App\DataFixtures;

use App\Entity\ShippingMethod;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class ShippingMethodFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $methods = [
            [
                'name' => 'Livraison standard',
                'description' => 'Livraison à domicile sous 3 à 5 jours ouvrés.',
                'price' => 5.90,
                'carrierCode' => 'LA_POSTE',
            ],
            [
                'name' => 'Livraison express',
                'description' => 'Livraison à domicile sous 24 à 48 heures.',
                'price' => 9.90,
                'carrierCode' => 'CHRONOPOST',
            ],
            [
                'name' => 'Click & Collect',
                'description' => 'Retrait gratuit en boutique partenaire.',
                'price' => 0.00,
                'carrierCode' => 'CLICK_COLLECT',
            ],
            [
                'name' => 'Mondial Relay',
                'description' => 'Livraison en point relais sous 3 à 6 jours.',
                'price' => 4.50,
                'carrierCode' => 'MONDIAL_RELAY',
            ],
        ];

        foreach ($methods as $data) {
            $method = new ShippingMethod();
            $method->setName($data['name'])
                   ->setDescription($data['description'])
                   ->setPrice($data['price'])
                   ->setCarrierCode($data['carrierCode']);

            $manager->persist($method);
        }

        $manager->flush();
    }
}
