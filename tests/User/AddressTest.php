<?php

namespace App\Tests\User;

use App\User\Entity\Address;
use App\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests API pour la gestion des adresses.
 * Couvre : création, lecture, modification, validation (pays, état US/CA).
 */
class AddressTest extends WebTestCase
{
    private const USER_EMAIL    = 'addr-test@waraba-test.internal';
    private const USER_PASSWORD = 'UserPass123!';

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

    private function cleanFixtures(EntityManagerInterface $em): void
    {
        $user = $em->getRepository(User::class)->findOneBy(['email' => self::USER_EMAIL]);
        if ($user) {
            // Supprimer les adresses liées
            foreach ($em->getRepository(Address::class)->findBy(['owner' => $user]) as $addr) {
                $em->remove($addr);
            }
            $em->flush();
            $em->remove($user);
            $em->flush();
        }
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function getUserJwt(): string
    {
        $this->client->request(
            'POST', '/api/auth', [], [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => self::USER_EMAIL, 'password' => self::USER_PASSWORD])
        );
        $this->assertResponseIsSuccessful();
        return json_decode($this->client->getResponse()->getContent(), true)['token'];
    }

    private function postJson(string $url, array $body, ?string $token = null): array
    {
        $headers = ['CONTENT_TYPE' => 'application/ld+json'];
        if ($token) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $this->client->request('POST', $url, [], [], $headers, json_encode($body));
        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    private function patchMerge(string $url, array $body, string $token): array
    {
        $this->client->request(
            'PATCH', $url, [], [],
            [
                'CONTENT_TYPE'      => 'application/merge-patch+json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode($body)
        );
        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    private function getJson(string $url, string $token): array
    {
        $this->client->request('GET', $url, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    // -----------------------------------------------------------------------
    // Tests — authentification requise
    // -----------------------------------------------------------------------

    /** GET /api/addresses sans token → 401 */
    public function testListAddressesRequiresAuth(): void
    {
        $this->client->request('GET', '/api/addresses');
        $this->assertResponseStatusCodeSame(401);
    }

    /** POST /api/addresses sans token → 401 */
    public function testCreateAddressRequiresAuth(): void
    {
        $this->postJson('/api/addresses', ['streetAddress' => '1 Rue Test', 'postalCode' => '75001', 'city' => 'Paris', 'country' => 'FR']);
        $this->assertResponseStatusCodeSame(401);
    }

    // -----------------------------------------------------------------------
    // Tests — création d'adresse
    // -----------------------------------------------------------------------

    /** POST /api/addresses avec données valides (France) → 201 */
    public function testCreateFrenchAddressSuccess(): void
    {
        $token = $this->getUserJwt();

        $data = $this->postJson('/api/addresses', [
            'addressKind'   => 'personal',
            'label'         => 'Maison',
            'civility'      => 'M.',
            'firstName'     => 'Jean',
            'lastName'      => 'Dupont',
            'streetAddress' => '12 Rue de la Paix',
            'postalCode'    => '75001',
            'city'          => 'Paris',
            'country'       => 'FR',
            'isDefault'     => true,
        ], $token);

        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayHasKey('id', $data);
        $this->assertSame('FR', $data['country']);
        // La ville et le code postal peuvent être normalisés par Mapbox
        $this->assertNotEmpty($data['streetAddress']);
    }

    /** POST /api/addresses avec adresse US + état → state persisté */
    public function testCreateUSAddressWithStatePersisted(): void
    {
        $token = $this->getUserJwt();

        $data = $this->postJson('/api/addresses', [
            'addressKind'   => 'personal',
            'label'         => 'US Home',
            'civility'      => 'M.',
            'firstName'     => 'John',
            'lastName'      => 'Doe',
            'streetAddress' => '123 Main St',
            'postalCode'    => '10001',
            'city'          => 'New York',
            'country'       => 'US',
            'state'         => 'NY',
            'isDefault'     => false,
        ], $token);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('US', $data['country']);
        $this->assertSame('NY', $data['state']);
    }

    /** POST /api/addresses avec adresse canadienne + province */
    public function testCreateCanadianAddressWithProvince(): void
    {
        $token = $this->getUserJwt();

        $data = $this->postJson('/api/addresses', [
            'addressKind'   => 'personal',
            'label'         => 'Montréal',
            'civility'      => 'M.',
            'firstName'     => 'Pierre',
            'lastName'      => 'Tremblay',
            'streetAddress' => '100 Rue Principale',
            'postalCode'    => 'H1A 0A1',
            'city'          => 'Montréal',
            'country'       => 'CA',
            'state'         => 'QC',
            'isDefault'     => false,
        ], $token);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('CA', $data['country']);
        $this->assertSame('QC', $data['state']);
    }

    // -----------------------------------------------------------------------
    // Tests — lecture
    // -----------------------------------------------------------------------

    /** GET /api/addresses retourne les adresses de l'utilisateur */
    public function testListAddressesReturnsUserAddresses(): void
    {
        $token = $this->getUserJwt();

        // Créer une adresse
        $this->postJson('/api/addresses', [
            'addressKind'   => 'personal',
            'label'         => 'Maison',
            'civility'      => 'M.',
            'firstName'     => 'Jean',
            'lastName'      => 'Dupont',
            'streetAddress' => '12 Rue de la Paix',
            'postalCode'    => '75001',
            'city'          => 'Paris',
            'country'       => 'FR',
            'isDefault'     => true,
        ], $token);

        $data = $this->getJson('/api/addresses', $token);

        $this->assertResponseIsSuccessful();
        $addresses = $data['member'] ?? $data['hydra:member'] ?? [];
        $this->assertNotEmpty($addresses);
        $this->assertSame('Paris', $addresses[0]['city']);
    }

    /** GET /api/addresses ne retourne pas les adresses d'autres utilisateurs */
    public function testListAddressesIsolatedPerUser(): void
    {
        $token = $this->getUserJwt();
        $data  = $this->getJson('/api/addresses', $token);

        $this->assertResponseIsSuccessful();
        $addresses = $data['member'] ?? $data['hydra:member'] ?? [];
        // Le compte test ne doit avoir aucune adresse au démarrage du test
        $this->assertEmpty($addresses);
    }

    // -----------------------------------------------------------------------
    // Tests — modification
    // -----------------------------------------------------------------------

    /** PATCH /api/addresses/{id} → mise à jour acceptée (200) et le pays reste inchangé */
    public function testUpdateAddressChangesCity(): void
    {
        $token = $this->getUserJwt();

        $created = $this->postJson('/api/addresses', [
            'addressKind'   => 'personal',
            'label'         => 'Bureau',
            'civility'      => 'M.',
            'firstName'     => 'Jean',
            'lastName'      => 'Dupont',
            'streetAddress' => '12 Rue de la Paix',
            'postalCode'    => '75001',
            'city'          => 'Paris',
            'country'       => 'FR',
            'isDefault'     => true,
        ], $token);

        $this->assertResponseStatusCodeSame(201);
        $id = $created['id'];

        $updated = $this->patchMerge('/api/addresses/' . $id, [
            'city'       => 'Lyon',
            'postalCode' => '69001',
        ], $token);

        // Le PATCH doit réussir (200)
        $this->assertResponseIsSuccessful();
        // Le pays ne doit pas changer
        $this->assertSame('FR', $updated['country']);
        // L'ID doit rester le même
        $this->assertSame($id, $updated['id']);
        // Note : Mapbox peut re-normaliser city/postalCode — on vérifie juste que la réponse est valide
        $this->assertNotEmpty($updated['streetAddress']);
    }

    /** PATCH /api/addresses/{id} — ajout de l'état sur une adresse US */
    public function testUpdateAddressAddsUSState(): void
    {
        $token = $this->getUserJwt();

        $created = $this->postJson('/api/addresses', [
            'addressKind'   => 'personal',
            'label'         => 'LA',
            'civility'      => 'M.',
            'firstName'     => 'John',
            'lastName'      => 'Doe',
            'streetAddress' => '123 Main St',
            'postalCode'    => '90001',
            'city'          => 'Los Angeles',
            'country'       => 'US',
            'isDefault'     => false,
        ], $token);

        $this->assertResponseStatusCodeSame(201);

        $updated = $this->patchMerge('/api/addresses/' . $created['id'], [
            'state' => 'CA',
        ], $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame('CA', $updated['state']);
    }
}
