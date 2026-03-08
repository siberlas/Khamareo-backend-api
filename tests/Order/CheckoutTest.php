<?php

namespace App\Tests\Order;

use App\Cart\Entity\Cart;
use App\Catalog\Entity\Product;
use App\Cart\Entity\CartItem;
use App\User\Entity\Address;
use App\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests API pour le flux de checkout.
 * Note : Stripe est configuré avec une clé fictive en test → les appels
 * de création de PaymentIntent échoueront. Ces tests couvrent la validation
 * des données d'entrée (adresse, contenu du panier, CGV) avant l'appel Stripe.
 */
class CheckoutTest extends WebTestCase
{
    private const USER_EMAIL    = 'checkout-test@waraba-test.internal';
    private const USER_PASSWORD = 'UserPass123!';

    private KernelBrowser $client;
    private int $testProductId;

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
        // Utilisateur
        $user = new User();
        $user->setEmail(self::USER_EMAIL);
        $user->setFirstName('Checkout');
        $user->setLastName('Tester');
        $user->setRoles(['ROLE_USER']);
        $user->setIsVerified(true);
        $user->setAcceptTerms(true);
        $user->setPassword($hasher->hashPassword($user, self::USER_PASSWORD));
        $em->persist($user);

        // Adresse de livraison
        $address = new Address();
        $address->setAddressKind('personal');
        $address->setFirstName('Checkout');
        $address->setLastName('Tester');
        $address->setStreetAddress('12 Rue de la Paix');
        $address->setPostalCode('75001');
        $address->setCity('Paris');
        $address->setCountry('FR');
        $address->setIsDefault(true);
        $address->setOwner($user);
        $em->persist($address);

        $em->flush();

        // Récupère un produit existant pour le panier (on ne crée pas de produit de test)
        $product = $em->getRepository(Product::class)->findOneBy(['isEnabled' => true]);

        // Panier avec un article
        $cart = new Cart();
        $cart->setOwner($user);
        $cart->setIsActive(true);
        $cart->setCreatedAt(new \DateTimeImmutable());
        $em->persist($cart);

        if ($product) {
            $this->testProductId = $product->getId();
            $item = new CartItem();
            $item->setCart($cart);
            $item->setProduct($product);
            $item->setQuantity(1);
            $item->setUnitPrice((float)($product->getPrice() ?? 10.00));
            $em->persist($item);
        }

        $em->flush();
    }

    private function cleanFixtures(EntityManagerInterface $em): void
    {
        $user = $em->getRepository(User::class)->findOneBy(['email' => self::USER_EMAIL]);
        if (!$user) {
            return;
        }

        // Supprimer commandes liées
        $conn = $em->getConnection();
        $conn->executeStatement(
            'DELETE FROM "order" WHERE owner_id = :id',
            ['id' => $user->getId()]
        );

        // Supprimer paniers et articles
        foreach ($em->getRepository(Cart::class)->findBy(['owner' => $user]) as $cart) {
            $em->remove($cart);
        }
        // Supprimer adresses
        foreach ($em->getRepository(Address::class)->findBy(['owner' => $user]) as $addr) {
            $em->remove($addr);
        }
        $em->flush();
        $em->remove($user);
        $em->flush();
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

    // -----------------------------------------------------------------------
    // Tests — authentification
    // -----------------------------------------------------------------------

    /** POST /api/cart/checkout sans guestToken ni auth → 400 (endpoint public, invités via guestToken) */
    public function testCheckoutWithoutCredentialsFails(): void
    {
        $this->postJson('/api/cart/checkout', []);
        $status = $this->client->getResponse()->getStatusCode();
        // L'endpoint est PUBLIC_ACCESS mais nécessite auth OU guestToken
        // Sans l'un ni l'autre : 400 (guestToken manquant ou devise invalide)
        $this->assertContains($status, [400, 401, 422]);
    }

    // -----------------------------------------------------------------------
    // Tests — validation des données
    // -----------------------------------------------------------------------

    /** POST /api/cart/checkout sans adresse → erreur de validation */
    public function testCheckoutWithoutAddressIsRejected(): void
    {
        $token = $this->getUserJwt();

        $this->postJson('/api/cart/checkout', [], $token);

        $status = $this->client->getResponse()->getStatusCode();
        // Doit rejeter avec 400 ou 422 (validation)
        $this->assertContains($status, [400, 422], 'Le checkout sans adresse doit être refusé');
    }

    // -----------------------------------------------------------------------
    // Tests — GET /api/cart
    // -----------------------------------------------------------------------

    /** GET /api/cart renvoie le panier de l'utilisateur connecté */
    public function testGetCartReturnsActiveCart(): void
    {
        $token = $this->getUserJwt();

        $this->client->request(
            'GET', '/api/cart', [], [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('items', $data);
    }

    /** GET /api/cart sans token → 200 (endpoint public, crée un panier invité) */
    public function testGetCartWithoutAuthCreatesGuestCart(): void
    {
        $this->client->request('GET', '/api/cart');
        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('guestToken', $data);
    }

    // -----------------------------------------------------------------------
    // Tests — GET /api/orders (liste des commandes utilisateur)
    // -----------------------------------------------------------------------

    /** GET /api/orders → 200 pour un utilisateur authentifié */
    public function testListOrdersReturnsSuccessForAuthenticatedUser(): void
    {
        $token = $this->getUserJwt();

        $this->client->request(
            'GET', '/api/orders', [], [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        // La réponse est une collection Hydra
        $orders = $data['member'] ?? $data['hydra:member'] ?? [];
        $this->assertIsArray($orders);
    }

    /** GET /api/orders sans token → 401 */
    public function testListOrdersRequiresAuth(): void
    {
        $this->client->request('GET', '/api/orders');
        $this->assertResponseStatusCodeSame(401);
    }
}
