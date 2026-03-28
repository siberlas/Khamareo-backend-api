<?php

namespace App\Tests\Order;

use App\Order\Entity\Order;
use App\Shared\Enum\OrderStatus;
use App\User\Entity\Address;
use App\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests for admin order address modification.
 * Covers: PATCH addresses, block after delivery.
 */
class OrderAddressTest extends WebTestCase
{
    private const ADMIN_EMAIL    = 'orderaddr-admin@waraba-test.internal';
    private const ADMIN_PASSWORD = 'AdminPass123!';

    private KernelBrowser $client;
    private ?string $orderId = null;
    private ?string $deliveredOrderId = null;

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
        $admin = new User();
        $admin->setEmail(self::ADMIN_EMAIL);
        $admin->setFirstName('OrderAddr');
        $admin->setLastName('Admin');
        $admin->setRoles(['ROLE_ADMIN', 'ROLE_USER']);
        $admin->setIsVerified(true);
        $admin->setAcceptTerms(true);
        $admin->setPassword($hasher->hashPassword($admin, self::ADMIN_PASSWORD));
        $em->persist($admin);

        // Shipping address
        $shippingAddr = new Address();
        $shippingAddr->setAddressKind('personal');
        $shippingAddr->setFirstName('Jean');
        $shippingAddr->setLastName('Dupont');
        $shippingAddr->setStreetAddress('10 Rue de la Paix');
        $shippingAddr->setPostalCode('75001');
        $shippingAddr->setCity('Paris');
        $shippingAddr->setCountry('FR');
        $shippingAddr->setIsDefault(false);
        $shippingAddr->setOwner(null); // Snapshot address (no owner)
        $em->persist($shippingAddr);

        // Editable order (PAID, no parcels, not shipped)
        $order = new Order();
        $order->setOwner($admin);
        $order->setTotalAmount(50.00);
        $order->setStatus(OrderStatus::PAID);
        $order->setOrderNumber('CMD-ADDR-' . random_int(1000, 9999));
        $order->setShippingAddress($shippingAddr);
        $em->persist($order);

        // Delivered order (shipped, so address cannot be modified)
        $deliveredAddr = new Address();
        $deliveredAddr->setAddressKind('personal');
        $deliveredAddr->setFirstName('Marie');
        $deliveredAddr->setLastName('Martin');
        $deliveredAddr->setStreetAddress('5 Avenue des Champs');
        $deliveredAddr->setPostalCode('75008');
        $deliveredAddr->setCity('Paris');
        $deliveredAddr->setCountry('FR');
        $deliveredAddr->setIsDefault(false);
        $deliveredAddr->setOwner(null);
        $em->persist($deliveredAddr);

        $deliveredOrder = new Order();
        $deliveredOrder->setOwner($admin);
        $deliveredOrder->setTotalAmount(75.00);
        $deliveredOrder->setStatus(OrderStatus::DELIVERED);
        $deliveredOrder->setOrderNumber('CMD-DLVR-' . random_int(1000, 9999));
        $deliveredOrder->setShippingAddress($deliveredAddr);
        $deliveredOrder->setShippedAt(new \DateTimeImmutable('-2 days'));
        $deliveredOrder->setDeliveredAt(new \DateTimeImmutable('-1 day'));
        $em->persist($deliveredOrder);

        $em->flush();

        $this->orderId = $order->getId()->toRfc4122();
        $this->deliveredOrderId = $deliveredOrder->getId()->toRfc4122();
    }

    private function cleanFixtures(EntityManagerInterface $em): void
    {
        $user = $em->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL]);
        if ($user) {
            $orders = $em->getRepository(Order::class)->findBy(['owner' => $user]);
            foreach ($orders as $order) {
                // Remove snapshot addresses (owner=null)
                $shippingAddr = $order->getShippingAddress();
                $billingAddr = $order->getBillingAddress();
                $order->setShippingAddress(null);
                $order->setBillingAddress(null);
                $em->flush();

                if ($shippingAddr && $shippingAddr->getOwner() === null) {
                    $em->remove($shippingAddr);
                }
                if ($billingAddr && $billingAddr->getOwner() === null) {
                    $em->remove($billingAddr);
                }

                foreach ($order->getItems() as $item) {
                    $em->remove($item);
                }
                $em->flush();
                $em->remove($order);
            }
            $em->flush();
            $em->remove($user);
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

    /** PATCH /api/admin/orders/{id}/addresses updates shipping address */
    public function testUpdateShippingAddress(): void
    {
        $token = $this->getAdminJwt();

        $this->client->request(
            'PATCH', '/api/admin/orders/' . $this->orderId . '/addresses', [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'shippingAddress' => [
                    'streetAddress' => '20 Boulevard Haussmann',
                    'city' => 'Paris',
                    'postalCode' => '75009',
                    'country' => 'FR',
                    'firstName' => 'Pierre',
                    'lastName' => 'Durand',
                ],
            ])
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('Boulevard Haussmann', $data['order']['shippingAddress']['street']);
        $this->assertNotEmpty($data['order']['shippingAddress']['postalCode']);
    }

    /** Cannot modify address after delivery (shippedAt is set) */
    public function testCannotModifyAddressAfterDelivery(): void
    {
        $token = $this->getAdminJwt();

        $this->client->request(
            'PATCH', '/api/admin/orders/' . $this->deliveredOrderId . '/addresses', [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'shippingAddress' => [
                    'streetAddress' => '99 Rue Impossible',
                    'city' => 'Lyon',
                    'postalCode' => '69001',
                    'country' => 'FR',
                ],
            ])
        );

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
    }
}
