<?php

namespace App\Cart\DataFixtures;

use App\Cart\Entity\Cart;
use App\Cart\Entity\CartItem;
use App\Catalog\Entity\Product;
use App\User\Entity\User;
use App\Shipping\Entity\ShippingMethod;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Faker\Factory;

class CartFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');

        $users           = $manager->getRepository(User::class)->findAll();
        $products        = $manager->getRepository(Product::class)->findAll();
        $shippingMethods = $manager->getRepository(ShippingMethod::class)->findAll();

        foreach ($users as $user) {

            // ========================================
            // 🛒 1) Création du panier
            // ========================================
            $cart = new Cart();
            $cart->setOwner($user);
            $cart->setIsActive(true);

            // Simule 30% de paniers invités
            if ($faker->boolean(30)) {
                $cart->setGuestToken(bin2hex(random_bytes(8)));
            }

            // Shipping method aléatoire
            $shippingMethod = $faker->randomElement($shippingMethods);

            // Shipping cost cohérent
            $cart->setShippingCost($shippingMethod->getPrice());

            // ========================================
            // 🧾 2) Simule un PaymentIntent Stripe
            // ========================================
           
            $manager->persist($cart);


            // ========================================
            // 🛍️ 3) Ajout des items au panier
            // ========================================
            for ($i = 0; $i < $faker->numberBetween(2, 4); $i++) {

                $product  = $faker->randomElement($products);
                $quantity = $faker->numberBetween(1, 5);

                $item = (new CartItem())
                    ->setCart($cart)
                    ->setProduct($product)
                    ->setQuantity($quantity)
                    ->setUnitPrice($product->getPrice());

                $manager->persist($item);

                $cart->addItem($item);
            }
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            ProductFixtures::class,
            ShippingMethodFixtures::class,
        ];
    }
}
