<?php

namespace App\Tests\ComingSoon;

use App\Shared\Entity\AppSettings;
use App\Shared\Entity\PreRegistration;
use App\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ComingSoonTest extends WebTestCase
{
    private const ADMIN_EMAIL    = 'cs-admin@waraba-test.internal';
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

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createFixtures(EntityManagerInterface $em, UserPasswordHasherInterface $hasher): void
    {
        // Admin user
        $admin = new User();
        $admin->setEmail(self::ADMIN_EMAIL);
        $admin->setFirstName('CS');
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
        // Admin user
        $userRepo = $em->getRepository(User::class);
        $user = $userRepo->findOneBy(['email' => self::ADMIN_EMAIL]);
        if ($user) {
            $em->remove($user);
        }

        // AppSettings créées par les tests
        $settingsRepo = $em->getRepository(AppSettings::class);
        foreach (['coming_soon_enabled', 'coming_soon_launch_date'] as $key) {
            $s = $settingsRepo->findOneBy(['settingKey' => $key]);
            if ($s) {
                $em->remove($s);
            }
        }

        // Pre-registrations créées par les tests
        $preRegRepo = $em->getRepository(PreRegistration::class);
        foreach (['test-cs@example.com', 'duplicate@example.com'] as $email) {
            $p = $preRegRepo->findOneBy(['email' => $email]);
            if ($p) {
                $em->remove($p);
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

    private function putJson(string $url, array $body, ?string $token = null): void
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($token) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $this->client->request('PUT', $url, [], [], $headers, json_encode($body));
    }

    private function getWithToken(string $url, string $token): void
    {
        $this->client->request('GET', $url, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }

    /** Retourne le JWT admin (route session → extrait le token) */
    private function getAdminJwt(): string
    {
        $this->postJson('/waraba-l19/login', [
            'email'    => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
        ]);
        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        return $data['token'];
    }

    // -------------------------------------------------------------------------
    // Tests — GET /api/status (public)
    // -------------------------------------------------------------------------

    /** GET /api/status retourne bien un JSON avec les clés attendues */
    public function testStatusEndpointReturnsJson(): void
    {
        $this->client->request('GET', '/api/status');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('coming_soon', $data);
        $this->assertArrayHasKey('launch_date', $data);
        $this->assertIsBool($data['coming_soon']);
    }

    /** GET /api/status est accessible sans authentification */
    public function testStatusIsPublic(): void
    {
        $this->client->request('GET', '/api/status');
        $status = $this->client->getResponse()->getStatusCode();
        $this->assertNotSame(401, $status);
        $this->assertNotSame(403, $status);
    }

    // -------------------------------------------------------------------------
    // Tests — POST /api/pre-register (public)
    // -------------------------------------------------------------------------

    /** POST /api/pre-register avec données valides → 201 */
    public function testPreRegisterSuccess(): void
    {
        $this->postJson('/api/pre-register', [
            'email'   => 'test-cs@example.com',
            'consent' => true,
        ]);

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $data);
    }

    /** POST /api/pre-register avec email invalide → 400 */
    public function testPreRegisterInvalidEmail(): void
    {
        $this->postJson('/api/pre-register', [
            'email'   => 'not-an-email',
            'consent' => true,
        ]);

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    /** POST /api/pre-register sans email → 400 */
    public function testPreRegisterMissingEmail(): void
    {
        $this->postJson('/api/pre-register', [
            'consent' => true,
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    /** POST /api/pre-register sans consentement → 400 */
    public function testPreRegisterMissingConsent(): void
    {
        $this->postJson('/api/pre-register', [
            'email'   => 'test-cs@example.com',
            'consent' => false,
        ]);

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    /** POST /api/pre-register avec un email déjà enregistré → 409 */
    public function testPreRegisterDuplicate(): void
    {
        $this->postJson('/api/pre-register', [
            'email'   => 'duplicate@example.com',
            'consent' => true,
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->postJson('/api/pre-register', [
            'email'   => 'duplicate@example.com',
            'consent' => true,
        ]);
        $this->assertResponseStatusCodeSame(409);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    // -------------------------------------------------------------------------
    // Tests — Admin routes JWT (/api/admin/coming-soon/*)
    // -------------------------------------------------------------------------

    /** GET /api/admin/coming-soon/settings sans auth → 401 */
    public function testAdminGetSettingsWithoutAuth(): void
    {
        $this->client->request('GET', '/api/admin/coming-soon/settings');
        $this->assertResponseStatusCodeSame(401);
    }

    /** GET /api/admin/coming-soon/settings avec JWT admin → 200 */
    public function testAdminGetSettingsAfterLogin(): void
    {
        $token = $this->getAdminJwt();

        $this->getWithToken('/api/admin/coming-soon/settings', $token);
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('enabled', $data);
        $this->assertArrayHasKey('launch_date', $data);
        $this->assertIsBool($data['enabled']);
    }

    /** PUT /api/admin/coming-soon/settings met à jour les paramètres */
    public function testAdminUpdateSettings(): void
    {
        $token = $this->getAdminJwt();

        $this->putJson('/api/admin/coming-soon/settings', [
            'enabled'     => false,
            'launch_date' => '2026-06-01',
        ], $token);
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['enabled']);
        $this->assertSame('2026-06-01', $data['launch_date']);
    }

    /** PUT /api/admin/coming-soon/settings peut réactiver le mode */
    public function testAdminToggleComingSoonOn(): void
    {
        $token = $this->getAdminJwt();

        $this->putJson('/api/admin/coming-soon/settings', ['enabled' => true], $token);
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['enabled']);
    }

    /** GET /api/admin/coming-soon/pre-registrations sans auth → 401 */
    public function testAdminListPreRegWithoutAuth(): void
    {
        $this->client->request('GET', '/api/admin/coming-soon/pre-registrations');
        $this->assertResponseStatusCodeSame(401);
    }

    /** GET /api/admin/coming-soon/pre-registrations avec JWT → 200 + structure paginée */
    public function testAdminListPreRegistrations(): void
    {
        $token = $this->getAdminJwt();

        $this->getWithToken('/api/admin/coming-soon/pre-registrations', $token);
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('items', $data);
        $this->assertArrayHasKey('page', $data);
        $this->assertArrayHasKey('limit', $data);
        $this->assertIsArray($data['items']);
    }

    /** GET /api/admin/coming-soon/pre-registrations/export avec JWT → CSV */
    public function testAdminExportPreRegistrations(): void
    {
        $token = $this->getAdminJwt();

        $this->getWithToken('/api/admin/coming-soon/pre-registrations/export', $token);
        $this->assertResponseIsSuccessful();

        $contentType = $this->client->getResponse()->headers->get('Content-Type');
        $this->assertStringContainsString('text/csv', $contentType);

        $disposition = $this->client->getResponse()->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('.csv', $disposition);
    }

    // -------------------------------------------------------------------------
    // Tests — POST /api/admin/coming-soon/launch
    // -------------------------------------------------------------------------

    /** POST /api/admin/coming-soon/launch sans auth → 401 */
    public function testLaunchRequiresAuth(): void
    {
        $this->client->request('POST', '/api/admin/coming-soon/launch');
        $this->assertResponseStatusCodeSame(401);
    }

    /** POST /api/admin/coming-soon/launch avec JWT → 200 + structure {sent, errors, total, coming_soon} */
    public function testLaunchReturnsEmailStats(): void
    {
        $token = $this->getAdminJwt();

        $this->client->request(
            'POST',
            '/api/admin/coming-soon/launch',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('sent', $data);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('coming_soon', $data);
        $this->assertIsInt($data['sent']);
        $this->assertIsInt($data['errors']);
    }

    /** POST /api/admin/coming-soon/launch désactive le mode coming soon */
    public function testLaunchDisablesComingSoon(): void
    {
        // S'assurer que coming_soon est activé avant le lancement
        $token = $this->getAdminJwt();
        $this->putJson('/api/admin/coming-soon/settings', ['enabled' => true], $token);

        // Lancement
        $this->client->request(
            'POST',
            '/api/admin/coming-soon/launch',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['coming_soon']);

        // Vérifier via GET /api/status
        $this->client->request('GET', '/api/status');
        $statusData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($statusData['coming_soon']);
    }

    /** POST /api/admin/coming-soon/launch crée le code promo AKWABA en base */
    public function testLaunchCreatesAkwabaPromoCode(): void
    {
        $token = $this->getAdminJwt();

        $this->client->request(
            'POST',
            '/api/admin/coming-soon/launch',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        $this->assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $promoRepo = $em->getRepository(\App\Marketing\Entity\PromoCode::class);
        $promo = $promoRepo->findOneBy(['code' => 'AKWABA']);

        $this->assertNotNull($promo, 'Le code promo AKWABA doit être créé en base');
        $this->assertSame('10.00', $promo->getDiscountPercentage());
        $this->assertTrue($promo->isActive());

        // Nettoyage
        $em->remove($promo);
        $em->flush();
    }
}
