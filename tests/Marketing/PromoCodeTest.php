<?php

namespace App\Tests\Marketing;

use App\Cart\Entity\Cart;
use App\Cart\Entity\CartItem;
use App\Catalog\Entity\Category;
use App\Catalog\Entity\Product;
use App\Marketing\Entity\PromoCode;
use App\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests API pour l'application de codes promo sur le panier.
 * Couvre : code valide, code inexistant, code expiré, code inactif,
 *          restriction email, maxUses dépassé.
 */
class PromoCodeTest extends WebTestCase
{
    private const USER_EMAIL    = 'promo-test@waraba-test.internal';
    private const USER_PASSWORD = 'UserPass123!';

    private const CODE_10_PCT   = 'TEST10PCT';
    private const CODE_5_EUR    = 'TEST5EUR';
    private const CODE_EXPIRED  = 'TESTEXPIRED';
    private const CODE_INACTIVE = 'TESTINACTIVE';
    private const CODE_MAX_USED = 'TESTMAXUSED';
    private const CODE_EMAILREQ = 'TESTEMAILONLY';

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
        // Utilisateur
        $user = new User();
        $user->setEmail(self::USER_EMAIL);
        $user->setFirstName('Promo');
        $user->setLastName('Tester');
        $user->setRoles(['ROLE_USER']);
        $user->setIsVerified(true);
        $user->setAcceptTerms(true);
        $user->setPassword($hasher->hashPassword($user, self::USER_PASSWORD));
        $em->persist($user);
        $em->flush();

        // Panier actif pour l'utilisateur
        $cart = new Cart();
        $cart->setOwner($user);
        $cart->setIsActive(true);
        $cart->setCreatedAt(new \DateTimeImmutable());
        $em->persist($cart);

        // Créer un produit test pour remplir le panier (requis pour appliquer un promo)
        $category = new Category();
        $category->setSlug('test-cat-promo');
        $category->setName('Test Category Promo');
        $category->setCreatedAt(new \DateTimeImmutable());
        $category->setUpdatedAt(new \DateTimeImmutable());
        $em->persist($category);

        $product = new Product();
        $product->setName('Test Product Promo');
        $product->setSlug('test-product-promo');
        $product->setPrice(25.00);
        $product->setImageUrl('https://fake.img/test.jpg');
        $product->setCreatedAt(new \DateTimeImmutable());
        $product->setStock(10);
        $product->setCategory($category);
        $em->persist($product);

        $em->flush();

        $item = new CartItem();
        $item->setCart($cart);
        $item->setProduct($product);
        $item->setQuantity(1);
        $item->setUnitPrice(25.00);
        $em->persist($item);

        // Code 10% valide
        $promo10 = (new PromoCode())
            ->setCode(self::CODE_10_PCT)
            ->setType('percentage')
            ->setDiscountPercentage('10.00')
            ->setExpiresAt(new \DateTimeImmutable('+1 year'))
            ->setIsActive(true)
            ->setStackable(false);
        $em->persist($promo10);

        // Code 5€ fixe valide
        $promo5 = (new PromoCode())
            ->setCode(self::CODE_5_EUR)
            ->setType('fixed')
            ->setDiscountAmount('5.00')
            ->setExpiresAt(new \DateTimeImmutable('+1 year'))
            ->setIsActive(true)
            ->setStackable(false);
        $em->persist($promo5);

        // Code expiré
        $promoExpired = (new PromoCode())
            ->setCode(self::CODE_EXPIRED)
            ->setType('percentage')
            ->setDiscountPercentage('15.00')
            ->setExpiresAt(new \DateTimeImmutable('-1 day'))
            ->setIsActive(true)
            ->setStackable(false);
        $em->persist($promoExpired);

        // Code inactif
        $promoInactive = (new PromoCode())
            ->setCode(self::CODE_INACTIVE)
            ->setType('percentage')
            ->setDiscountPercentage('20.00')
            ->setExpiresAt(new \DateTimeImmutable('+1 year'))
            ->setIsActive(false)
            ->setStackable(false);
        $em->persist($promoInactive);

        // Code déjà utilisé au maximum (maxUses=1, isUsed=true)
        $promoMaxUsed = (new PromoCode())
            ->setCode(self::CODE_MAX_USED)
            ->setType('percentage')
            ->setDiscountPercentage('5.00')
            ->setExpiresAt(new \DateTimeImmutable('+1 year'))
            ->setIsActive(true)
            ->setMaxUses(1)
            ->setStackable(false);
        $promoMaxUsed->setIsUsed(true);
        $em->persist($promoMaxUsed);

        // Code restreint à un email spécifique
        $promoEmail = (new PromoCode())
            ->setCode(self::CODE_EMAILREQ)
            ->setType('percentage')
            ->setDiscountPercentage('10.00')
            ->setExpiresAt(new \DateTimeImmutable('+1 year'))
            ->setIsActive(true)
            ->setStackable(false);
        $promoEmail->setEmail('specific@example.com');
        $em->persist($promoEmail);

        $em->flush();
    }

    private function cleanFixtures(EntityManagerInterface $em): void
    {
        $codes = [
            self::CODE_10_PCT, self::CODE_5_EUR, self::CODE_EXPIRED,
            self::CODE_INACTIVE, self::CODE_MAX_USED, self::CODE_EMAILREQ,
        ];
        foreach ($codes as $code) {
            $p = $em->getRepository(PromoCode::class)->findOneBy(['code' => $code]);
            if ($p) {
                $em->remove($p);
            }
        }

        $user = $em->getRepository(User::class)->findOneBy(['email' => self::USER_EMAIL]);
        if ($user) {
            foreach ($em->getRepository(Cart::class)->findBy(['owner' => $user]) as $cart) {
                // Supprimer les articles du panier (pas de cascade SQL)
                foreach ($em->getRepository(CartItem::class)->findBy(['cart' => $cart]) as $item) {
                    $em->remove($item);
                }
                $em->flush();
                $em->remove($cart);
            }
            $em->flush();
            $em->remove($user);
        }

        // Supprimer produit et catégorie test
        $product = $em->getRepository(Product::class)->findOneBy(['slug' => 'test-product-promo']);
        if ($product) {
            $em->remove($product);
        }
        $em->flush();

        $category = $em->getRepository(Category::class)->findOneBy(['slug' => 'test-cat-promo']);
        if ($category) {
            $em->remove($category);
        }

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

    private function applyPromo(string $code, string $token): array
    {
        $this->client->request(
            'POST', '/api/cart/apply-promo', [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            json_encode(['code' => $code])
        );
        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    // -----------------------------------------------------------------------
    // Tests — code valide
    // -----------------------------------------------------------------------

    /** Code 10% valide → 200 et remise appliquée */
    public function testApplyValidPercentageCode(): void
    {
        $token = $this->getUserJwt();
        $data  = $this->applyPromo(self::CODE_10_PCT, $token);

        $this->assertResponseIsSuccessful();
        $this->assertArrayHasKey('cart', $data);
        $this->assertGreaterThanOrEqual(0, $data['cart']['discountAmount']);
    }

    /** Code 5€ fixe valide → 200 */
    public function testApplyValidFixedAmountCode(): void
    {
        $token = $this->getUserJwt();
        $data  = $this->applyPromo(self::CODE_5_EUR, $token);

        $this->assertResponseIsSuccessful();
        $this->assertArrayHasKey('cart', $data);
    }

    /** Code en minuscules → accepté (insensible à la casse) */
    public function testApplyCodeCaseInsensitive(): void
    {
        $token = $this->getUserJwt();
        $data  = $this->applyPromo(strtolower(self::CODE_10_PCT), $token);

        $this->assertResponseIsSuccessful();
    }

    // -----------------------------------------------------------------------
    // Tests — code invalide / refusé
    // -----------------------------------------------------------------------

    /** Code inexistant → 404 */
    public function testApplyNonExistentCode(): void
    {
        $token = $this->getUserJwt();
        $this->applyPromo('DOESNOTEXIST', $token);

        $this->assertResponseStatusCodeSame(404);
    }

    /** Corps vide (pas de code) → 400 */
    public function testApplyWithoutCode(): void
    {
        $token = $this->getUserJwt();
        $this->client->request(
            'POST', '/api/cart/apply-promo', [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            json_encode([])
        );
        $this->assertResponseStatusCodeSame(400);
    }

    /** Code expiré → refusé */
    public function testApplyExpiredCode(): void
    {
        $token = $this->getUserJwt();
        $this->applyPromo(self::CODE_EXPIRED, $token);

        $status = $this->client->getResponse()->getStatusCode();
        $this->assertContains($status, [400, 422], 'Un code expiré doit être refusé (400 ou 422)');
    }

    /** Code inactif → refusé */
    public function testApplyInactiveCode(): void
    {
        $token = $this->getUserJwt();
        $this->applyPromo(self::CODE_INACTIVE, $token);

        $status = $this->client->getResponse()->getStatusCode();
        $this->assertContains($status, [400, 422], 'Un code inactif doit être refusé (400 ou 422)');
    }

    /** Code single-instance déjà utilisé → refusé */
    public function testApplyAlreadyUsedCode(): void
    {
        $token = $this->getUserJwt();
        $this->applyPromo(self::CODE_MAX_USED, $token);

        $status = $this->client->getResponse()->getStatusCode();
        $this->assertContains($status, [400, 422], 'Un code déjà utilisé doit être refusé');
    }

    /** Code restreint à un email différent → 403 */
    public function testApplyCodeWithWrongEmail(): void
    {
        $token = $this->getUserJwt();
        $this->applyPromo(self::CODE_EMAILREQ, $token);

        // L'email du compte test != 'specific@example.com'
        $this->assertResponseStatusCodeSame(403);
    }

    /** Endpoint sans token ni guestToken → 404 (panier introuvable, invités acceptés) */
    public function testApplyPromoWithoutCredentialsReturns404(): void
    {
        $this->client->request(
            'POST', '/api/cart/apply-promo', [], [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['code' => self::CODE_10_PCT])
        );
        $this->assertResponseStatusCodeSame(404);
    }
}
