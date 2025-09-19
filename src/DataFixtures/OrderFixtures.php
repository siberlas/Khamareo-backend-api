<?php

namespace App\DataFixtures;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\User;
use App\Entity\Product;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Faker\Factory;

class OrderFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create();

        $users = $manager->getRepository(User::class)->findAll();
        $products = $manager->getRepository(Product::class)->findAll();

        foreach ($users as $user) {
            // Chaque user fait entre 1 et 2 commandes
            for ($o = 0; $o < $faker->numberBetween(1, 2); $o++) {
                $order = new Order();
                $order->setOwner($user) // propriété "customer" au lieu de "user"
                      ->setStatus($faker->randomElement(['pending', 'paid', 'shipped']))
                      ->setTotalAmount(0) // sera recalculé
                      ->setPaymentId($faker->uuid())
                      ->setDeliveryAddress($faker->address);

                $manager->persist($order);

                $total = 0;

                // Chaque commande a 2 à 4 produits
                for ($i = 0; $i < $faker->numberBetween(2, 4); $i++) {
                    $product = $faker->randomElement($products);
                    $quantity = $faker->numberBetween(1, 3);
                    $price = $product->getPrice();

                    $orderItem = new OrderItem();
                    $orderItem->setCustomerOrder($order)
                              ->setProduct($product)
                              ->setQuantity($quantity)
                              ->setPrice($price);

                    $manager->persist($orderItem);

                    $total += $price * $quantity;
                }

                $order->setTotalAmount($total);
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
