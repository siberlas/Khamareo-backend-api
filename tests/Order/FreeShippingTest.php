<?php

namespace App\Tests\Order;

use App\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests for free shipping settings.
 * Covers: threshold-based free shipping enabled/disabled.
 */
class FreeShippingTest extends WebTestCase
{
    private const ADMIN_EMAIL    = 'freeship-admin@waraba-test.internal';
    private const ADMIN_PASSWORD = 'AdminPass123!';

    private KernelBrowser $client;

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
        $admin->setFirstName('FreeShip');
        $admin->setLastName('Admin');
        $admin->setRoles(['ROLE_ADMIN', 'ROLE_USER']);
        $admin->setIsVerified(true);
        $admin->setAcceptTerms(true);
        $admin->setPassword($hasher->hashPassword($admin, self::ADMIN_PASSWORD));
        $em->persist($admin);
        $em->flush();
    }

    private function cleanFixtures(EntityManagerInterface $em): void
    {
        $user = $em->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL]);
        if ($user) {
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

    /** When freeShippingEnabled=true and threshold is set, store settings reflect it */
    public function testFreeShippingEnabledWithThreshold(): void
    {
        $token = $this->getAdminJwt();

        // Set free shipping with threshold of 50 EUR
        $this->client->request(
            'PUT', '/api/admin/store-settings', [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'freeShippingEnabled' => true,
                'freeShippingThreshold' => 50.00,
            ])
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['freeShippingEnabled']);
        $this->assertEquals(50.0, $data['freeShippingThreshold']);

        // Verify via public GET endpoint
        $this->client->request('GET', '/api/public/store-settings');
        $this->assertResponseIsSuccessful();
        $publicData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($publicData['freeShippingEnabled']);
        $this->assertEquals(50.0, $publicData['freeShippingThreshold']);
    }

    /** When freeShippingEnabled=false, threshold does not apply */
    public function testFreeShippingDisabled(): void
    {
        $token = $this->getAdminJwt();

        // Disable free shipping
        $this->client->request(
            'PUT', '/api/admin/store-settings', [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'freeShippingEnabled' => false,
                'freeShippingThreshold' => 50.00,
            ])
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['freeShippingEnabled']);

        // Public endpoint also shows disabled
        $this->client->request('GET', '/api/public/store-settings');
        $this->assertResponseIsSuccessful();
        $publicData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($publicData['freeShippingEnabled']);
    }
}
