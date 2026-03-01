<?php

namespace App\Tests\Security;

use App\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AdminFirewallTest extends WebTestCase
{
    private const ADMIN_EMAIL    = 'test-admin@waraba-test.internal';
    private const USER_EMAIL     = 'test-user@waraba-test.internal';
    private const ADMIN_PASSWORD = 'AdminPass123!';
    private const USER_PASSWORD  = 'UserPass123!';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        // createClient() DOIT être appelé avant tout getContainer() en Symfony 6+
        $this->client = static::createClient();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->cleanTestUsers($em);
        $this->createTestUsers($em, $hasher);
    }

    protected function tearDown(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanTestUsers($em);

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createTestUsers(EntityManagerInterface $em, UserPasswordHasherInterface $hasher): void
    {
        $admin = new User();
        $admin->setEmail(self::ADMIN_EMAIL);
        $admin->setFirstName('Test');
        $admin->setLastName('Admin');
        $admin->setRoles(['ROLE_ADMIN', 'ROLE_USER']);
        $admin->setIsVerified(true);
        $admin->setAcceptTerms(true);
        $admin->setPassword($hasher->hashPassword($admin, self::ADMIN_PASSWORD));
        $em->persist($admin);

        $user = new User();
        $user->setEmail(self::USER_EMAIL);
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setRoles(['ROLE_USER']);
        $user->setIsVerified(true);
        $user->setAcceptTerms(true);
        $user->setPassword($hasher->hashPassword($user, self::USER_PASSWORD));
        $em->persist($user);

        $em->flush();
    }

    private function cleanTestUsers(EntityManagerInterface $em): void
    {
        $repo = $em->getRepository(User::class);

        foreach ([self::ADMIN_EMAIL, self::USER_EMAIL] as $email) {
            $existing = $repo->findOneBy(['email' => $email]);
            if ($existing) {
                $em->remove($existing);
            }
        }
        $em->flush();
    }

    private function postJson(string $url, array $body): void
    {
        $this->client->request(
            'POST',
            $url,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($body),
        );
    }

    // -------------------------------------------------------------------------
    // Tests — portail admin /waraba-l19
    // -------------------------------------------------------------------------

    /** POST /waraba-l19/login avec ROLE_ADMIN → 200 + données + token JWT */
    public function testAdminLoginSuccess(): void
    {
        $this->postJson('/waraba-l19/login', [
            'email'    => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertSame(self::ADMIN_EMAIL, $data['email']);
        $this->assertContains('ROLE_ADMIN', $data['roles']);
        // Le backend doit retourner un JWT pour les appels /api/admin/*
        $this->assertArrayHasKey('token', $data);
        $this->assertNotEmpty($data['token']);
    }

    /** POST /waraba-l19/login avec ROLE_USER seulement → 403 */
    public function testAdminLoginForbiddenForRegularUser(): void
    {
        $this->postJson('/waraba-l19/login', [
            'email'    => self::USER_EMAIL,
            'password' => self::USER_PASSWORD,
        ]);

        $this->assertResponseStatusCodeSame(403);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Access denied', $data['error']);
    }

    /** POST /waraba-l19/login avec mauvais mot de passe → 401 */
    public function testAdminLoginWrongPassword(): void
    {
        $this->postJson('/waraba-l19/login', [
            'email'    => self::ADMIN_EMAIL,
            'password' => 'wrong-password',
        ]);

        $this->assertResponseStatusCodeSame(401);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    /** GET /waraba-l19/me sans authentification → 401 */
    public function testAdminMeWithoutAuth(): void
    {
        $this->client->request('GET', '/waraba-l19/me');

        $this->assertResponseStatusCodeSame(401);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    /** GET /waraba-l19/me après login admin → 200 + données */
    public function testAdminMeAfterLogin(): void
    {
        $this->postJson('/waraba-l19/login', [
            'email'    => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
        ]);
        $this->assertResponseIsSuccessful();

        // Le même client réutilise le cookie de session automatiquement
        $this->client->request('GET', '/waraba-l19/me');
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(self::ADMIN_EMAIL, $data['email']);
        $this->assertContains('ROLE_ADMIN', $data['roles']);
    }

    // -------------------------------------------------------------------------
    // Tests — firewall client /api/auth (ROLE_ADMIN bloqué)
    // -------------------------------------------------------------------------

    /** POST /api/auth avec un compte ROLE_ADMIN → bloqué (401) */
    public function testAdminBlockedOnCustomerLogin(): void
    {
        $this->postJson('/api/auth', [
            'email'    => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    /** POST /api/auth avec un compte ROLE_USER → 200 + token JWT */
    public function testCustomerJwtLoginStillWorks(): void
    {
        $this->postJson('/api/auth', [
            'email'    => self::USER_EMAIL,
            'password' => self::USER_PASSWORD,
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('token', $data);
        $this->assertNotEmpty($data['token']);
    }
}
