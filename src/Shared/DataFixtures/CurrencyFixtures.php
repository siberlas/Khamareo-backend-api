<?php
// src/DataFixtures/CurrencyFixtures.php

namespace App\Shared\DataFixtures;

use App\Shared\Entity\Currency;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class CurrencyFixtures extends Fixture
{
    // Constantes pour référencer les devises dans d'autres fixtures
    public const CURRENCY_EUR = 'currency_eur';
    public const CURRENCY_USD = 'currency_usd';
    public const CURRENCY_GBP = 'currency_gbp';
    public const CURRENCY_CAD = 'currency_cad';
    public const CURRENCY_CHF = 'currency_chf';

    public function load(ObjectManager $manager): void
    {
        // EUR - Devise par défaut
        $eur = new Currency();
        $eur->setCode('EUR')
            ->setSymbol('€')
            ->setName('Euro')
            ->setExchangeRateToEur('1.000000')
            ->setIsDefault(true);
        $manager->persist($eur);
        $this->addReference(self::CURRENCY_EUR, $eur);

        // USD - Dollar américain
        $usd = new Currency();
        $usd->setCode('USD')
            ->setSymbol('$')
            ->setName('US Dollar')
            ->setExchangeRateToEur('1.100000')
            ->setIsDefault(false);
        $manager->persist($usd);
        $this->addReference(self::CURRENCY_USD, $usd);

        // GBP - Livre sterling
        $gbp = new Currency();
        $gbp->setCode('GBP')
            ->setSymbol('£')
            ->setName('British Pound')
            ->setExchangeRateToEur('0.850000')
            ->setIsDefault(false);
        $manager->persist($gbp);
        $this->addReference(self::CURRENCY_GBP, $gbp);

        // CAD - Dollar canadien
        $cad = new Currency();
        $cad->setCode('CAD')
            ->setSymbol('$')
            ->setName('Canadian Dollar')
            ->setExchangeRateToEur('1.500000')
            ->setIsDefault(false);
        $manager->persist($cad);
        $this->addReference(self::CURRENCY_CAD, $cad);

        // CHF - Franc suisse
        $chf = new Currency();
        $chf->setCode('CHF')
            ->setSymbol('CHF')
            ->setName('Swiss Franc')
            ->setExchangeRateToEur('0.950000')
            ->setIsDefault(false);
        $manager->persist($chf);
        $this->addReference(self::CURRENCY_CHF, $chf);

        $manager->flush();
    }
}