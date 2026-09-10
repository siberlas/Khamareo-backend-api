<?php

namespace App\Tests\Cart;

use App\Cart\Entity\Cart;
use App\Cart\Entity\CartItem;
use App\Cart\Service\CartWeightCalculator;
use App\Catalog\Entity\Product;
use App\Catalog\Enum\ProductType;
use PHPUnit\Framework\TestCase;

/**
 * Détection panier physique / numérique / mixte + exclusion des livres
 * numériques du calcul du poids d'expédition.
 */
class CartDigitalItemsTest extends TestCase
{
    private function product(ProductType $type, ?int $weightGrams = 500): Product
    {
        return (new Product())
            ->setName($type->value)
            ->setProductType($type)
            ->setWeightGrams($weightGrams);
    }

    private function cartWith(Product ...$products): Cart
    {
        $cart = new Cart();
        foreach ($products as $p) {
            $item = (new CartItem())->setProduct($p)->setQuantity(1)->setUnitPrice(10.0);
            $cart->addItem($item);
        }
        return $cart;
    }

    public function testPhysicalOnlyCart(): void
    {
        $cart = $this->cartWith($this->product(ProductType::PHYSICAL));
        $this->assertTrue($cart->hasPhysicalItems());
        $this->assertFalse($cart->hasDigitalItems());
        $this->assertFalse($cart->hasOnlyDigitalItems());
    }

    public function testDigitalOnlyCart(): void
    {
        $cart = $this->cartWith($this->product(ProductType::DIGITAL, null));
        $this->assertFalse($cart->hasPhysicalItems());
        $this->assertTrue($cart->hasDigitalItems());
        $this->assertTrue($cart->hasOnlyDigitalItems());
    }

    public function testMixedCart(): void
    {
        $cart = $this->cartWith(
            $this->product(ProductType::PHYSICAL),
            $this->product(ProductType::DIGITAL, null),
        );
        $this->assertTrue($cart->hasPhysicalItems());
        $this->assertTrue($cart->hasDigitalItems());
        $this->assertFalse($cart->hasOnlyDigitalItems());
        $this->assertCount(1, $cart->getShippableItems());
    }

    public function testEmptyCartIsNotDigitalOnly(): void
    {
        $this->assertFalse((new Cart())->hasOnlyDigitalItems());
    }

    public function testWeightCalculatorIgnoresDigitalItems(): void
    {
        $cart = $this->cartWith(
            $this->product(ProductType::PHYSICAL, 800),
            $this->product(ProductType::DIGITAL, null),
        );

        $weightKg = (new CartWeightCalculator())->getTotalWeightFromCart($cart);
        $this->assertSame(0.8, $weightKg);
    }
}
