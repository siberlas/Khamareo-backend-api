<?php

namespace App\Shipping\DataFixtures;

use App\Shipping\Entity\ShippingRate;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class ShippingRateFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
      $rates = [
            // 🚚 LA POSTE - France
            ['provider' => 'LA_POSTE', 'zone' => 'FR', 'min' => 0.00, 'max' => 0.25, 'price' => 5.25],
            ['provider' => 'LA_POSTE', 'zone' => 'FR', 'min' => 0.25, 'max' => 0.50, 'price' => 7.35],
            ['provider' => 'LA_POSTE', 'zone' => 'FR', 'min' => 0.50, 'max' => 1.00, 'price' => 8.65],
            ['provider' => 'LA_POSTE', 'zone' => 'FR', 'min' => 1.00, 'max' => 2.00, 'price' => 9.90],
            ['provider' => 'LA_POSTE', 'zone' => 'FR', 'min' => 2.00, 'max' => 5.00, 'price' => 12.90],
            ['provider' => 'LA_POSTE', 'zone' => 'FR', 'min' => 5.00, 'max' => 10.00, 'price' => 17.50],
            ['provider' => 'LA_POSTE', 'zone' => 'FR', 'min' => 10.00, 'max' => 30.00, 'price' => 25.00],

            // 📦 MONDIAL RELAY - France
            ['provider' => 'MONDIAL_RELAY', 'zone' => 'FR', 'min' => 0.00, 'max' => 0.25, 'price' => 3.99],
            ['provider' => 'MONDIAL_RELAY', 'zone' => 'FR', 'min' => 0.25, 'max' => 0.50, 'price' => 4.99],
            ['provider' => 'MONDIAL_RELAY', 'zone' => 'FR', 'min' => 0.50, 'max' => 1.00, 'price' => 5.99],
            ['provider' => 'MONDIAL_RELAY', 'zone' => 'FR', 'min' => 1.00, 'max' => 2.00, 'price' => 6.99],
            ['provider' => 'MONDIAL_RELAY', 'zone' => 'FR', 'min' => 2.00, 'max' => 5.00, 'price' => 8.99],
            ['provider' => 'MONDIAL_RELAY', 'zone' => 'FR', 'min' => 5.00, 'max' => 10.00, 'price' => 11.50],
            ['provider' => 'MONDIAL_RELAY', 'zone' => 'FR', 'min' => 10.00, 'max' => 30.00, 'price' => 15.90],

            // ⚡ COLISSIMO - France
            ['provider' => 'COLISSIMO', 'zone' => 'FR', 'min' => 0.00, 'max' => 0.25, 'price' => 6.00],
            ['provider' => 'COLISSIMO', 'zone' => 'FR', 'min' => 0.25, 'max' => 0.50, 'price' => 7.00],
            ['provider' => 'COLISSIMO', 'zone' => 'FR', 'min' => 0.50, 'max' => 1.00, 'price' => 8.50],
            ['provider' => 'COLISSIMO', 'zone' => 'FR', 'min' => 1.00, 'max' => 2.00, 'price' => 9.90],
            ['provider' => 'COLISSIMO', 'zone' => 'FR', 'min' => 2.00, 'max' => 5.00, 'price' => 13.50],
            ['provider' => 'COLISSIMO', 'zone' => 'FR', 'min' => 5.00, 'max' => 10.00, 'price' => 18.00],
            ['provider' => 'COLISSIMO', 'zone' => 'FR', 'min' => 10.00, 'max' => 30.00, 'price' => 28.00],

            // 🇪🇺 LA POSTE - Europe
            ['provider' => 'LA_POSTE', 'zone' => 'EU', 'min' => 0.00, 'max' => 0.25, 'price' => 9.50],
            ['provider' => 'LA_POSTE', 'zone' => 'EU', 'min' => 0.25, 'max' => 0.50, 'price' => 11.20],
            ['provider' => 'LA_POSTE', 'zone' => 'EU', 'min' => 0.50, 'max' => 1.00, 'price' => 13.80],
            ['provider' => 'LA_POSTE', 'zone' => 'EU', 'min' => 1.00, 'max' => 2.00, 'price' => 16.50],
            ['provider' => 'LA_POSTE', 'zone' => 'EU', 'min' => 2.00, 'max' => 5.00, 'price' => 22.00],
            ['provider' => 'LA_POSTE', 'zone' => 'EU', 'min' => 5.00, 'max' => 10.00, 'price' => 32.00],

            // 🌍 LA POSTE - International
            ['provider' => 'LA_POSTE', 'zone' => 'INTL', 'min' => 0.00, 'max' => 0.25, 'price' => 12.90],
            ['provider' => 'LA_POSTE', 'zone' => 'INTL', 'min' => 0.25, 'max' => 0.50, 'price' => 15.50],
            ['provider' => 'LA_POSTE', 'zone' => 'INTL', 'min' => 0.50, 'max' => 1.00, 'price' => 18.90],
            ['provider' => 'LA_POSTE', 'zone' => 'INTL', 'min' => 1.00, 'max' => 2.00, 'price' => 23.50],
            ['provider' => 'LA_POSTE', 'zone' => 'INTL', 'min' => 2.00, 'max' => 5.00, 'price' => 35.00],
            ['provider' => 'LA_POSTE', 'zone' => 'INTL', 'min' => 5.00, 'max' => 10.00, 'price' => 55.00],
            ['provider' => 'LA_POSTE', 'zone' => 'INTL', 'min' => 10.00, 'max' => 30.00, 'price' => 80.00],
        ];

        foreach ($rates as $r) {
            $rate = (new ShippingRate())
                ->setProvider($r['provider'])
                ->setZone($r['zone'])
                ->setMinWeight($r['min'])
                ->setMaxWeight($r['max'])
                ->setPrice($r['price']);

            $manager->persist($rate);
        }

        $manager->flush();
    }
}
