<?php

namespace App\DataFixtures;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\User;
use App\Entity\ShippingMethod;
use App\Entity\Address;
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

        $users           = $manager->getRepository(User::class)->findAll();
        $products        = $manager->getRepository(Product::class)->findAll();
        $shippingMethods = $manager->getRepository(ShippingMethod::class)->findAll();
        $addresses       = $manager->getRepository(Address::class)->findAll();

        foreach ($users as $user) {

            for ($i = 0; $i < 2; $i++) {

                // ===========================================================
                // 🟦 1) Adresse source (addressKind = personal/business)
                // ===========================================================
                $source = $faker->randomElement($addresses);

                // ===========================================================
                // 🟦 2) Création du snapshot SHIPPING
                // ===========================================================
                $shippingSnapshot = (new Address())
                    ->setAddressKind($source->getAddressKind())
                    ->setIsBusiness($source->isBusiness())
                    ->setCivility($source->getCivility())
                    ->setFirstName($source->getFirstName())
                    ->setLastName($source->getLastName())
                    ->setCompanyName($source->getCompanyName())
                    ->setStreetAddress($source->getStreetAddress())
                    ->setCity($source->getCity())
                    ->setPostalCode($source->getPostalCode())
                    ->setCountry($source->getCountry())
                    ->setPhone($source->getPhone())
                    ->setLabel('Snapshot shipping '.$i)
                    ->setIsDefault(false)
                    ->setOwner(null); // un snapshot n’appartient à aucun user

                $manager->persist($shippingSnapshot);

                // ===========================================================
                // 🟦 3) Création du snapshot BILLING
                // ===========================================================
                $billingSnapshot = (new Address())
                    ->setAddressKind($source->getAddressKind())
                    ->setIsBusiness($source->isBusiness())
                    ->setCivility($source->getCivility())
                    ->setFirstName($source->getFirstName())
                    ->setLastName($source->getLastName())
                    ->setCompanyName($source->getCompanyName())
                    ->setStreetAddress($source->getStreetAddress())
                    ->setCity($source->getCity())
                    ->setPostalCode($source->getPostalCode())
                    ->setCountry($source->getCountry())
                    ->setPhone($source->getPhone())
                    ->setLabel('Snapshot billing '.$i)
                    ->setIsDefault(false)
                    ->setOwner(null);

                $manager->persist($billingSnapshot);

                // ===========================================================
                // 🟦 4) Création de la commande
                // ===========================================================
                $shippingMethod = $faker->randomElement($shippingMethods);

                $order = (new Order())
                    ->setOwner($user)
                    ->setStatus(OrderStatus::PENDING)
                    ->setShippingMethod($shippingMethod)
                    ->setShippingAddress($shippingSnapshot)
                    ->setBillingAddress($billingSnapshot)
                    ->setPaymentStatus('unpaid')
                    ->setPaymentMethod('card')
                    ->setCurrency('EUR')
                    ->setShippingCost(null);

                $manager->persist($order);

                // ===========================================================
                // 🟦 5) Ajout des OrderItem
                // ===========================================================
                $total = 0;

                for ($j = 0; $j < $faker->numberBetween(2, 4); $j++) {
                    $product   = $faker->randomElement($products);
                    $quantity  = $faker->numberBetween(1, 3);
                    $unitPrice = $product->getPrice();

                    $item = (new OrderItem())
                        ->setCustomerOrder($order)
                        ->setProduct($product)
                        ->setQuantity($quantity)
                        ->setUnitPrice($unitPrice);

                    $manager->persist($item);

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
            AddressFixtures::class,
        ];
    }
}
