<?php

namespace App\DataFixtures;

use App\Entity\Cart;
use App\Entity\CartItem;
use App\Entity\Product;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Faker\Factory;

class CartFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');

        $users = $manager->getRepository(User::class)->findAll();
        $products = $manager->getRepository(Product::class)->findAll();

        foreach ($users as $user) {
            $cart = new Cart();
            $cart->setOwner($user);
            $cart->setIsActive(true);

            // Simule 30 % de paniers invités
            if ($faker->boolean(30)) {
                $cart->setGuestToken(bin2hex(random_bytes(8)));
            }

            $manager->persist($cart);

            // Ajoute 2 à 4 produits par panier
            for ($i = 0; $i < $faker->numberBetween(2, 4); $i++) {
                $product = $faker->randomElement($products);
                $quantity = $faker->numberBetween(1, 5);

                $item = new CartItem();
                $item->setCart($cart)
                     ->setProduct($product)
                     ->setQuantity($quantity)
                     ->setUnitPrice($product->getPrice());

                $cart->addItem($item);
                $manager->persist($item);
            }
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            ProductFixtures::class,
        ];
    }
}
