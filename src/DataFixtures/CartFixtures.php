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
        $faker = Factory::create();

        // Récupération des users créés dans UserFixtures
        $users = $manager->getRepository(User::class)->findAll();
        $products = $manager->getRepository(Product::class)->findAll();

        foreach ($users as $user) {
            $cart = new Cart();
            $cart->setOwner($user); // propriété "owner" au lieu de "user"

            $manager->persist($cart);

            // Ajouter 2-3 produits dans le panier
            for ($i = 0; $i < $faker->numberBetween(2, 3); $i++) {
                $product = $faker->randomElement($products);

                $item = new CartItem();
                $item->setCart($cart)
                     ->setProduct($product)
                     ->setQuantity($faker->numberBetween(1, 5));

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
