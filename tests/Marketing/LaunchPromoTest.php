<?php

namespace App\Tests\Marketing;

use App\Marketing\Entity\PromoCode;
use App\Shared\Entity\AppSettings;
use App\Shared\Entity\PreRegistration;
use App\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests for launch promo code generation.
 * Covers: AKWAABA code creation per pre-registered email, type, maxUses, stackable.
 */
class LaunchPromoTest extends WebTestCase
{
    private const ADMIN_EMAIL    = 'launch-admin@waraba-test.internal';
    private const ADMIN_PASSWORD = 'AdminPass123!';

    private const TEST_PREREG_EMAILS = [
        'launch-prereg1@example.com',
        'launch-prereg2@example.com',
    ];

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
        $admin->setFirstName('Launch');
        $admin->setLastName('Admin');
        $admin->setRoles(['ROLE_ADMIN', 'ROLE_USER']);
        $admin->setIsVerified(true);
        $admin->setAcceptTerms(true);
        $admin->setPassword($hasher->hashPassword($admin, self::ADMIN_PASSWORD));
        $em->persist($admin);

        // Create pre-registrations
        foreach (self::TEST_PREREG_EMAILS as $email) {
            $preReg = new PreRegistration();
            $preReg->setEmail($email);
            $preReg->setConsentGiven(true);
            $em->persist($preReg);
        }

        $em->flush();
    }

    private function cleanFixtures(EntityManagerInterface $em): void
    {
        // Clean promo codes created by launch
        $promoRepo = $em->getRepository(PromoCode::class);
        foreach (self::TEST_PREREG_EMAILS as $email) {
            $promo = $promoRepo->findOneBy(['email' => $email, 'type' => 'launch']);
            if ($promo) {
                $em->remove($promo);
            }
        }

        // Clean pre-registrations
        $preRegRepo = $em->getRepository(PreRegistration::class);
        foreach (self::TEST_PREREG_EMAILS as $email) {
            $preReg = $preRegRepo->findOneBy(['email' => $email]);
            if ($preReg) {
                $em->remove($preReg);
            }
        }

        // Clean settings
        $settingsRepo = $em->getRepository(AppSettings::class);
        foreach (['coming_soon_enabled', 'coming_soon_launch_date'] as $key) {
            $s = $settingsRepo->findOneBy(['settingKey' => $key]);
            if ($s) {
                $em->remove($s);
            }
        }

        $user = $em->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL]);
        if ($user) {
            $em->remove($user);
        }

        $em->flush();
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

    /** Launch creates individual AKWAABA-xxx codes per pre-registered email */
    public function testLaunchCreatesIndividualCodes(): void
    {
        $token = $this->getAdminJwt();

        $this->client->request(
            'POST', '/api/admin/coming-soon/launch', [], [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $promoRepo = $em->getRepository(PromoCode::class);

        foreach (self::TEST_PREREG_EMAILS as $email) {
            $promo = $promoRepo->findOneBy(['email' => $email, 'type' => 'launch']);
            $this->assertNotNull($promo, "A launch promo code must exist for $email");
            $this->assertStringStartsWith('AKWAABA-', $promo->getCode());
        }
    }

    /** Launch codes are type 'launch' with maxUses=1 */
    public function testLaunchCodesHaveCorrectTypeAndMaxUses(): void
    {
        $token = $this->getAdminJwt();

        $this->client->request(
            'POST', '/api/admin/coming-soon/launch', [], [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $promoRepo = $em->getRepository(PromoCode::class);

        $promo = $promoRepo->findOneBy(['email' => self::TEST_PREREG_EMAILS[0], 'type' => 'launch']);
        $this->assertNotNull($promo);
        $this->assertSame('launch', $promo->getType());
        $this->assertSame(1, $promo->getMaxUses());
    }

    /** Launch codes are not stackable */
    public function testLaunchCodesAreNotStackable(): void
    {
        $token = $this->getAdminJwt();

        $this->client->request(
            'POST', '/api/admin/coming-soon/launch', [], [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $promoRepo = $em->getRepository(PromoCode::class);

        $promo = $promoRepo->findOneBy(['email' => self::TEST_PREREG_EMAILS[0], 'type' => 'launch']);
        $this->assertNotNull($promo);
        $this->assertFalse($promo->isStackable());
    }
}
