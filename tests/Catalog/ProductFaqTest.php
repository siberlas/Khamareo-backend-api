<?php

namespace App\Tests\Catalog;

use App\Catalog\Entity\Category;
use App\Catalog\Entity\Product;
use App\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests for product FAQ and preparation fields.
 * Covers: creating, reading, updating product faq/preparation via API.
 */
class ProductFaqTest extends WebTestCase
{
    private const ADMIN_EMAIL    = 'faq-admin@waraba-test.internal';
    private const ADMIN_PASSWORD = 'AdminPass123!';

    private KernelBrowser $client;
    private ?string $productSlug = null;

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
        $admin->setFirstName('FAQ');
        $admin->setLastName('Admin');
        $admin->setRoles(['ROLE_ADMIN', 'ROLE_USER']);
        $admin->setIsVerified(true);
        $admin->setAcceptTerms(true);
        $admin->setPassword($hasher->hashPassword($admin, self::ADMIN_PASSWORD));
        $em->persist($admin);

        $category = new Category();
        $category->setSlug('test-cat-faq');
        $category->setName('Test Category FAQ');
        $category->setCreatedAt(new \DateTimeImmutable());
        $category->setUpdatedAt(new \DateTimeImmutable());
        $em->persist($category);

        // Product with preparation and faq for read/update tests
        $product = new Product();
        $product->setName('Test Product FAQ');
        $product->setSlug('test-product-faq');
        $product->setPrice(20.00);
        $product->setStock(3);
        $product->setImageUrl('https://fake.img/faq.jpg');
        $product->setCategory($category);
        $product->setPreparation('Brew for 5 minutes.');
        $product->setFaq([
            ['question' => 'Can I drink it cold?', 'answer' => 'Yes!'],
        ]);
        $em->persist($product);

        $em->flush();

        $this->productSlug = 'test-product-faq';
    }

    private function cleanFixtures(EntityManagerInterface $em): void
    {
        $product = $em->getRepository(Product::class)->findOneBy(['slug' => 'test-product-faq']);
        if ($product) {
            $em->remove($product);
        }
        $em->flush();

        $category = $em->getRepository(Category::class)->findOneBy(['slug' => 'test-cat-faq']);
        if ($category) {
            $em->remove($category);
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

    /** Product created with preparation and faq fields is persisted correctly */
    public function testProductWithPreparationAndFaqIsPersisted(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $product = $em->getRepository(Product::class)->findOneBy(['slug' => $this->productSlug]);

        $this->assertNotNull($product);
        $this->assertSame('Brew for 5 minutes.', $product->getPreparation());
        $this->assertNotNull($product->getFaq());
        $this->assertCount(1, $product->getFaq());
        $this->assertSame('Can I drink it cold?', $product->getFaq()[0]['question']);
    }

    /** GET /api/products/{slug} returns preparation and faq */
    public function testReadProductReturnsPreparationAndFaq(): void
    {
        $this->client->request('GET', '/api/products/' . $this->productSlug);
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Brew for 5 minutes.', $data['preparation']);
        $this->assertCount(1, $data['faq']);
        $this->assertSame('Can I drink it cold?', $data['faq'][0]['question']);
        $this->assertSame('Yes!', $data['faq'][0]['answer']);
    }

    /** PATCH /api/products/{slug} updates faq */
    public function testUpdateProductFaq(): void
    {
        $token = $this->getAdminJwt();

        $newFaq = [
            ['question' => 'New question?', 'answer' => 'New answer.'],
            ['question' => 'Another?', 'answer' => 'Sure.'],
        ];

        $this->client->request(
            'PATCH', '/api/products/' . $this->productSlug, [], [],
            [
                'CONTENT_TYPE' => 'application/merge-patch+json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode(['faq' => $newFaq])
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertCount(2, $data['faq']);
        $this->assertSame('New question?', $data['faq'][0]['question']);
    }
}
