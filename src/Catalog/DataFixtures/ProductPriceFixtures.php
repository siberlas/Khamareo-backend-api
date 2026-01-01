<?php
// src/DataFixtures/ProductPriceFixtures.php

namespace App\Catalog\DataFixtures;

use App\Catalog\Entity\Product;
use App\Catalog\Entity\ProductPrice;
use App\Shared\Entity\Currency;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class ProductPriceFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Récupérer toutes les devises avec leurs taux
        $currencies = [
            'EUR' => ['rate' => 1.000000, 'ref' => CurrencyFixtures::CURRENCY_EUR],
            'USD' => ['rate' => 1.100000, 'ref' => CurrencyFixtures::CURRENCY_USD],
            'GBP' => ['rate' => 0.850000, 'ref' => CurrencyFixtures::CURRENCY_GBP],
            'CAD' => ['rate' => 1.500000, 'ref' => CurrencyFixtures::CURRENCY_CAD],
            'CHF' => ['rate' => 0.950000, 'ref' => CurrencyFixtures::CURRENCY_CHF],
        ];

        // Récupérer tous les produits
        $products = $manager->getRepository(Product::class)->findAll();

        foreach ($products as $product) {
            // Prix de base en EUR (depuis Product.price)
            $basePriceEur = (float) $product->getPrice();
            $baseOriginalPriceEur = $product->getOriginalPrice() ? (float) $product->getOriginalPrice() : null;

            foreach ($currencies as $code => $currencyData) {
                $exchangeRate = $currencyData['rate'];

                // Calculer le prix dans la devise
                $priceInCurrency = $basePriceEur * $exchangeRate;
                $originalPriceInCurrency = $baseOriginalPriceEur ? $baseOriginalPriceEur * $exchangeRate : null;

                // Arrondir à des prix psychologiques (finir par .99, .95, .49)
                $priceInCurrency = $this->roundToPsychologicalPrice($priceInCurrency);
                if ($originalPriceInCurrency) {
                    $originalPriceInCurrency = $this->roundToPsychologicalPrice($originalPriceInCurrency);
                }

                // Créer le ProductPrice
                $productPrice = new ProductPrice();
                $productPrice->setProduct($product)
                    ->setCurrencyCode($code)  // ✅ Stocker juste le code
                    ->setPrice((string) $priceInCurrency)
                    ->setOriginalPrice($originalPriceInCurrency ? (string) $originalPriceInCurrency : null);

                $manager->persist($productPrice);
            }
        }

        $manager->flush();
    }

    /**
     * Arrondir à un prix psychologique (finir par .99, .95, .49)
     */
    private function roundToPsychologicalPrice(float $price): float
    {
        $integerPart = floor($price);
        $decimalPart = $price - $integerPart;

        // Choisir .99, .95 ou .49 selon la valeur
        if ($decimalPart >= 0.90) {
            return $integerPart + 0.99;
        } elseif ($decimalPart >= 0.75) {
            return $integerPart + 0.95;
        } elseif ($decimalPart >= 0.40) {
            return $integerPart + 0.49;
        } else {
            return $integerPart + 0.99;
        }
    }

    public function getDependencies(): array
    {
        return [
            ProductFixtures::class,
            CurrencyFixtures::class,
        ];
    }
}