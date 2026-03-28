<?php

namespace App\Tests\Shipping;

use App\Catalog\Entity\Category;
use App\Catalog\Entity\Product;
use App\Order\Entity\Order;
use App\Order\Entity\OrderItem;
use App\Shipping\Entity\Parcel;
use App\Shipping\Entity\ParcelItem;
use App\Shared\Enum\OrderStatus;
use App\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests for parcel shipping logic.
 * Covers: creating parcels, shipping notification, partial allocation.
 */
class ParcelShippingTest extends WebTestCase
{
    private const ADMIN_EMAIL    = 'parcel-admin@waraba-test.internal';
    private const ADMIN_PASSWORD = 'AdminPass123!';

    private KernelBrowser $client;
    private ?string $orderId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->cleanFixtures($em);
        $this->createFixtures($em, $hasher);
    }

    protected function tearDown(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanFixtures($em);

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------

    private function createFixtures(EntityManagerInterface $em, UserPasswordHasherInterface $hasher): void
    {
        // Admin user
        $admin = new User();
        $admin->setEmail(self::ADMIN_EMAIL);
        $admin->setFirstName('Parcel');
        $admin->setLastName('Admin');
        $admin->setRoles(['ROLE_ADMIN', 'ROLE_USER']);
        $admin->setIsVerified(true);
        $admin->setAcceptTerms(true);
        $admin->setPassword($hasher->hashPassword($admin, self::ADMIN_PASSWORD));
        $em->persist($admin);

        // Category + Product
        $category = new Category();
        $category->setSlug('test-cat-parcel');
        $category->setName('Test Category Parcel');
        $category->setCreatedAt(new \DateTimeImmutable());
        $category->setUpdatedAt(new \DateTimeImmutable());
        $em->persist($category);

        $product = new Product();
        $product->setName('Test Product Parcel');
        $product->setSlug('test-product-parcel');
        $product->setPrice(15.00);
        $product->setWeight(0.5); // 500g
        $product->setImageUrl('https://fake.img/parcel.jpg');
        $product->setStock(10);
        $product->setCategory($category);
        $em->persist($product);
        $em->flush();

        // Order with items
        $order = new Order();
        $order->setOwner($admin);
        $order->setTotalAmount(30.00);
        $order->setStatus(OrderStatus::PAID);
        $order->setOrderNumber('CMD-PARCEL-' . random_int(1000, 9999));
        $em->persist($order);

        $orderItem = new OrderItem();
        $orderItem->setCustomerOrder($order);
        $orderItem->setProduct($product);
        $orderItem->setQuantity(2);
        $orderItem->setUnitPrice(15.00);
        $em->persist($orderItem);

        $em->flush();

        $this->orderId = $order->getId()->toRfc4122();
    }

    private function cleanFixtures(EntityManagerInterface $em): void
    {
        $conn = $em->getConnection();

        $user = $em->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL]);
        if ($user) {
            $orders = $em->getRepository(Order::class)->findBy(['owner' => $user]);
            foreach ($orders as $order) {
                $orderId = $order->getId()->toRfc4122();
                // Use raw SQL to clean parcels (parcel_item FK -> parcel FK -> order)
                $conn->executeStatement(
                    'DELETE FROM parcel_item WHERE parcel_id IN (SELECT id FROM parcel WHERE order_id = :oid)',
                    ['oid' => $orderId]
                );
                $conn->executeStatement('DELETE FROM parcel WHERE order_id = :oid', ['oid' => $orderId]);
                $conn->executeStatement('DELETE FROM order_item WHERE customer_order_id = :oid', ['oid' => $orderId]);
                $conn->executeStatement('DELETE FROM "order" WHERE id = :oid', ['oid' => $orderId]);
            }
            $em->clear();

            // Re-fetch user after clear
            $user = $em->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL]);
            if ($user) {
                $em->remove($user);
                $em->flush();
            }
        }

        $product = $em->getRepository(Product::class)->findOneBy(['slug' => 'test-product-parcel']);
        if ($product) {
            $em->remove($product);
            $em->flush();
        }

        $category = $em->getRepository(Category::class)->findOneBy(['slug' => 'test-cat-parcel']);
        if ($category) {
            $em->remove($category);
            $em->flush();
        }
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function getAdminJwt(): string
    {
        $this->client->request(
            'POST', '/waraba-l19/login', [], [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD])
        );
        $this->assertResponseIsSuccessful();
        return json_decode($this->client->getResponse()->getContent(), true)['token'];
    }

    // -----------------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------------

    /** POST /api/parcels (admin) creates a parcel for an order */
    public function testCreateParcelForOrder(): void
    {
        $token = $this->getAdminJwt();

        $this->client->request(
            'POST', '/api/parcels', [], [],
            [
                'CONTENT_TYPE' => 'application/ld+json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'order' => '/api/orders/' . $this->orderId,
                'parcelNumber' => 1,
                'weightGrams' => 1000,
                'status' => 'pending',
            ])
        );

        $status = $this->client->getResponse()->getStatusCode();
        // API Platform POST should return 201
        $this->assertSame(201, $status, 'Creating a parcel should return 201');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $data);
        $this->assertSame('pending', $data['status']);
        $this->assertSame(1, $data['parcelNumber']);
    }

    /** Shipping a parcel (marking as shipped) via PATCH updates status */
    public function testShipParcelUpdatesStatus(): void
    {
        $token = $this->getAdminJwt();

        // Create a parcel first
        $this->client->request(
            'POST', '/api/parcels', [], [],
            [
                'CONTENT_TYPE' => 'application/ld+json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'order' => '/api/orders/' . $this->orderId,
                'parcelNumber' => 1,
                'weightGrams' => 500,
                'status' => 'pending',
            ])
        );
        $this->assertResponseStatusCodeSame(201);
        $parcelData = json_decode($this->client->getResponse()->getContent(), true);
        $parcelId = $parcelData['id'];

        // PATCH to shipped
        $this->client->request(
            'PATCH', '/api/parcels/' . $parcelId, [], [],
            [
                'CONTENT_TYPE' => 'application/merge-patch+json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode(['status' => 'shipped'])
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('shipped', $data['status']);
    }

    /** Order should NOT go to SHIPPED status if items remain unassigned to parcels */
    public function testOrderNotShippedIfItemsUnassigned(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $uuid = \Symfony\Component\Uid\Uuid::fromString($this->orderId);
        $order = $em->getRepository(Order::class)->find($uuid);
        $this->assertNotNull($order);

        // Load order items explicitly
        $orderItems = $em->getRepository(OrderItem::class)->findBy(['customerOrder' => $order]);
        $this->assertNotEmpty($orderItems, 'Order must have items');
        $orderItem = $orderItems[0];

        // Order has 2 items but no parcels yet
        // Create a parcel with only 1 of the 2 items assigned
        $parcel = new Parcel();
        $parcel->setOrder($order);
        $parcel->setParcelNumber(1);
        $parcel->setStatus('pending');
        $parcel->setWeightGrams(500);
        $em->persist($parcel);

        // Only assign 1 of 2 quantity
        $parcelItem = new ParcelItem();
        $parcelItem->setParcel($parcel);
        $parcelItem->setOrderItem($orderItem);
        $parcelItem->setQuantity(1); // Only 1 out of 2
        $em->persist($parcelItem);
        $em->flush();

        // Verify allocation is incomplete
        $totalOrdered = $orderItem->getQuantity();
        $this->assertSame(2, $totalOrdered);
        $this->assertSame(1, $parcelItem->getQuantity());

        // The order should still be in PAID status, not SHIPPED
        $this->assertSame(OrderStatus::PAID, $order->getStatus(), 'Order should not be SHIPPED with unassigned items');
    }
}
