<?php

namespace App\DataFixtures;

use App\Entity\ShippingMethod;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class ShippingMethodFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // ✅ LA POSTE
        $laPoste = (new ShippingMethod())
            ->setName('Livraison standard - La Poste')
            ->setDescription('Livraison à domicile sous 3 à 5 jours ouvrés.')
            ->setPrice(0) // Prix indicatif, le vrai prix vient de ShippingRate
            ->setCarrierCode('LA_POSTE');

        // ✅ MONDIAL RELAY
        $mondialRelay = (new ShippingMethod())
            ->setName('Livraison en point relais - Mondial Relay')
            ->setDescription('Retrait en point relais sous 3 à 5 jours ouvrés.')
            ->setPrice(0)
            ->setCarrierCode('MONDIAL_RELAY');

        // ✅ COLISSIMO
        $colissimo = (new ShippingMethod())
            ->setName('Livraison express - Colissimo')
            ->setDescription('Livraison rapide à domicile sous 24 à 48h.')
            ->setPrice(0)
            ->setCarrierCode('COLISSIMO');

        $manager->persist($laPoste);
        $manager->persist($mondialRelay);
        $manager->persist($colissimo);

        $manager->flush();
    }
}
