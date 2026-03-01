<?php

namespace App\Shipping\DataFixtures;

use App\Shipping\Entity\Carrier;
use App\Shipping\Entity\ShippingMode;
use App\Shipping\Entity\CarrierMode;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class CarrierFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // ========================================
        // 1. CARRIERS (Transporteurs)
        // ========================================

        $colissimo = (new Carrier())
            ->setName('Colissimo')
            ->setCode('colissimo')
            ->setDescription('La Poste - Livraison rapide et fiable')
            ->setMaxWeightGrams(30000) // 30 kg
            ->setMinWeightGrams(1)
            ->setIsActive(true)
            ->setLogoUrl('/images/carriers/colissimo.png');
        $manager->persist($colissimo);

        $mondialRelay = (new Carrier())
            ->setName('Mondial Relay')
            ->setCode('mondialrelay')
            ->setDescription('Points Relais et Lockers en France et Europe')
            ->setMaxWeightGrams(25000) // 25 kg
            ->setMinWeightGrams(10)
            ->setIsActive(true)
            ->setLogoUrl('/images/carriers/mondialrelay.png');
        $manager->persist($mondialRelay);

        $chronopost = (new Carrier())
            ->setName('Chronopost')
            ->setCode('chronopost')
            ->setDescription('Livraison express avant 13h')
            ->setMaxWeightGrams(30000) // 30 kg
            ->setMinWeightGrams(1)
            ->setIsActive(false) // Désactivé pour l'instant
            ->setLogoUrl('/images/carriers/chronopost.png');
        $manager->persist($chronopost);

        // ========================================
        // 2. SHIPPING MODES (Modes de livraison)
        // ========================================

        $home = (new ShippingMode())
            ->setName('Domicile')
            ->setCode('home')
            ->setDescription('Livraison à votre domicile')
            ->setRequiresPickupPoint(false)
            ->setIsActive(true)
            ->setIcon('home');
        $manager->persist($home);

        $relayPoint = (new ShippingMode())
            ->setName('Point Relais')
            ->setCode('relay_point')
            ->setDescription('Retrait en point relais')
            ->setRequiresPickupPoint(true)
            ->setIsActive(true)
            ->setIcon('store');
        $manager->persist($relayPoint);

        $locker = (new ShippingMode())
            ->setName('Locker')
            ->setCode('locker')
            ->setDescription('Retrait en consigne automatique')
            ->setRequiresPickupPoint(true)
            ->setIsActive(true)
            ->setIcon('local_shipping');
        $manager->persist($locker);

        $express = (new ShippingMode())
            ->setName('Express')
            ->setCode('express')
            ->setDescription('Livraison express avant 13h')
            ->setRequiresPickupPoint(false)
            ->setIsActive(false) // Désactivé pour l'instant
            ->setIcon('bolt');
        $manager->persist($express);

        // ========================================
        // 3. CARRIER MODES (Combinaisons)
        // ========================================

        // --- COLISSIMO ---

        // Colissimo Domicile - France métropolitaine (Monaco + Andorre)
        $colissimoDomFR = (new CarrierMode())
            ->setCarrier($colissimo)
            ->setShippingMode($home)
            ->setSupportedZones(['FR'])
            ->setIsActive(true)
            ->setEstimatedDeliveryDays(2)
            ->setDeliveryMinDays(2)
            ->setDeliveryMaxDays(2)
            ->setDeliveryDaysUnit('working_days')
            ->setColissimoProductCodeKey('france_metro')
            ->setBasePrice(5.49);
        $manager->persist($colissimoDomFR);

        // Colissimo Outre-mer Standard (OM1 + OM2)
        $colissimoOmStandard = (new CarrierMode())
            ->setCarrier($colissimo)
            ->setShippingMode($home)
            ->setSupportedZones(['OM1', 'OM2'])
            ->setIsActive(true)
            ->setDeliveryMinDays(6)
            ->setDeliveryMaxDays(18)
            ->setDeliveryDaysUnit('calendar_days')
            ->setDeliveryDaysNote('Variable en temps de crise')
            ->setColissimoProductCodeKey('outre_mer')
            ->setBasePrice(12.02);
        $manager->persist($colissimoOmStandard);

        // Colissimo Outre-mer ECO (OM1 + OM2)
        $colissimoOmEco = (new CarrierMode())
            ->setCarrier($colissimo)
            ->setShippingMode($home)
            ->setSupportedZones(['OM1', 'OM2'])
            ->setIsActive(true)
            ->setDeliveryMinDays(13)
            ->setDeliveryMaxDays(31)
            ->setDeliveryDaysUnit('working_days')
            ->setDeliveryDaysNote('Offre économique, délais plus longs')
            ->setColissimoProductCodeKey('outre_mer_eco')
            ->setBasePrice(8.41);
        $manager->persist($colissimoOmEco);

        // Colissimo International Europe (EU + CH + UK)
        $colissimoIntlEurope = (new CarrierMode())
            ->setCarrier($colissimo)
            ->setShippingMode($home)
            ->setSupportedZones(['EU', 'CH', 'UK'])
            ->setIsActive(true)
            ->setDeliveryMinDays(3)
            ->setDeliveryMaxDays(15)
            ->setDeliveryDaysUnit('working_days')
            ->setColissimoProductCodeKey('union_europeenne')
            ->setBasePrice(14.99);
        $manager->persist($colissimoIntlEurope);

        // Colissimo International Monde (B + C)
        $colissimoIntlWorld = (new CarrierMode())
            ->setCarrier($colissimo)
            ->setShippingMode($home)
            ->setSupportedZones(['B', 'C'])
            ->setIsActive(true)
            ->setDeliveryMinDays(3)
            ->setDeliveryMaxDays(15)
            ->setDeliveryDaysUnit('working_days')
            ->setColissimoProductCodeKey('international')
            ->setBasePrice(23.79);
        $manager->persist($colissimoIntlWorld);

        // --- MONDIAL RELAY ---

        // Mondial Relay Point Relais - France
        $mrRelayFR = (new CarrierMode())
            ->setCarrier($mondialRelay)
            ->setShippingMode($relayPoint)
            ->setSupportedZones(['FR'])
            ->setIsActive(true)
            ->setEstimatedDeliveryDays(4)
            ->setDeliveryMinDays(3)
            ->setDeliveryMaxDays(5)
            ->setDeliveryDaysUnit('working_days')
            ->setBasePrice(4.10);
        $manager->persist($mrRelayFR);

        // Mondial Relay Locker - France
        $mrLockerFR = (new CarrierMode())
            ->setCarrier($mondialRelay)
            ->setShippingMode($locker)
            ->setSupportedZones(['FR'])
            ->setIsActive(true)
            ->setEstimatedDeliveryDays(4)
            ->setDeliveryMinDays(3)
            ->setDeliveryMaxDays(5)
            ->setDeliveryDaysUnit('working_days')
            ->setBasePrice(4.10);
        $manager->persist($mrLockerFR);

        // Mondial Relay Domicile - France
        $mrHomeFR = (new CarrierMode())
            ->setCarrier($mondialRelay)
            ->setShippingMode($home)
            ->setSupportedZones(['FR'])
            ->setIsActive(true)
            ->setEstimatedDeliveryDays(4)
            ->setDeliveryMinDays(3)
            ->setDeliveryMaxDays(5)
            ->setDeliveryDaysUnit('working_days')
            ->setBasePrice(4.99);
        $manager->persist($mrHomeFR);

        // Mondial Relay Point Relais - International (EU)
        $mrRelayEU = (new CarrierMode())
            ->setCarrier($mondialRelay)
            ->setShippingMode($relayPoint)
            ->setSupportedZones(['EU'])
            ->setIsActive(true)
            ->setEstimatedDeliveryDays(5)
            ->setDeliveryMinDays(3)
            ->setDeliveryMaxDays(5)
            ->setDeliveryDaysUnit('working_days')
            ->setDeliveryDaysNote('Selon pays de destination')
            ->setBasePrice(4.60);
        $manager->persist($mrRelayEU);

        // Mondial Relay Domicile - International (EU)
        $mrHomeEU = (new CarrierMode())
            ->setCarrier($mondialRelay)
            ->setShippingMode($home)
            ->setSupportedZones(['EU'])
            ->setIsActive(true)
            ->setEstimatedDeliveryDays(5)
            ->setDeliveryMinDays(3)
            ->setDeliveryMaxDays(5)
            ->setDeliveryDaysUnit('working_days')
            ->setDeliveryDaysNote('Selon pays de destination')
            ->setBasePrice(12.50);
        $manager->persist($mrHomeEU);

        // --- CHRONOPOST (désactivé) ---

        $chronopostExpressFR = (new CarrierMode())
            ->setCarrier($chronopost)
            ->setShippingMode($express)
            ->setSupportedZones(['FR'])
            ->setIsActive(false)
            ->setEstimatedDeliveryDays(1)
            ->setBasePrice(19.90);
        $manager->persist($chronopostExpressFR);

        // ========================================
        // FLUSH
        // ========================================

        $manager->flush();

        $this->addReference('carrier-colissimo', $colissimo);
        $this->addReference('carrier-mondialrelay', $mondialRelay);
        $this->addReference('mode-home', $home);
        $this->addReference('mode-relay', $relayPoint);
    }
}