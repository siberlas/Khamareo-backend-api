<?php

namespace App\DataFixtures;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\User;
use App\Entity\ShippingMethod;
use App\Entity\ShippingAddress;
use App\Enum\OrderStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Faker\Factory;

class OrderFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');

        $users = $manager->getRepository(User::class)->findAll();
        $products = $manager->getRepository(Product::class)->findAll();
        $shippingMethods = $manager->getRepository(ShippingMethod::class)->findAll();
        $shippingAddresses = $manager->getRepository(ShippingAddress::class)->findAll();

        foreach ($users as $user) {
            for ($i = 0; $i < $faker->numberBetween(1, 2); $i++) {
                $order = new Order();
                $order->setOwner($user)
                      ->setStatus($faker->randomElement([
                          OrderStatus::PENDING,
                          OrderStatus::PAID,
                          OrderStatus::SHIPPED,
                          OrderStatus::DELIVERED
                      ]))
                      ->setShippingMethod($faker->randomElement($shippingMethods))
                      ->setShippingAddress($faker->randomElement($shippingAddresses))
                      ->setPaymentMethod('card')
                      ->setPaymentStatus('paid')
                      ->setIsLocked(true)
                      ->setDeliveryAddress($faker->address)
                      ->setBillingAddress($faker->address)
                      ->setCurrency('EUR');

                $manager->persist($order);

                $total = 0;

                // Chaque commande contient 2 à 4 produits
                for ($j = 0; $j < $faker->numberBetween(2, 4); $j++) {
                    $product = $faker->randomElement($products);
                    $quantity = $faker->numberBetween(1, 3);
                    $unitPrice = $product->getPrice();

                    $orderItem = new OrderItem();
                    $orderItem->setCustomerOrder($order)
                              ->setProduct($product)
                              ->setQuantity($quantity)
                              ->setUnitPrice($unitPrice);

                    $manager->persist($orderItem);
                    $order->addItem($orderItem);

                    $total += $unitPrice * $quantity;
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
            ShippingMethodFixtures::class,
            ShippingAddressFixtures::class,
        ];
    }
}
