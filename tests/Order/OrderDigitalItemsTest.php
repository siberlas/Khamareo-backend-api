<?php

namespace App\Tests\Order;

use App\Catalog\Entity\Product;
use App\Catalog\Enum\ProductType;
use App\Order\Entity\Order;
use App\Order\Entity\OrderItem;
use PHPUnit\Framework\TestCase;

class OrderDigitalItemsTest extends TestCase
{
    private function item(ProductType $type): OrderItem
    {
        $product = (new Product())->setName($type->value)->setProductType($type);
        return (new OrderItem())
            ->setProduct($product)
            ->setProductType($type)
            ->setQuantity(1)
            ->setUnitPrice(10.0);
    }

    private function orderWith(ProductType ...$types): Order
    {
        $order = new Order();
        foreach ($types as $t) {
            $order->addItem($this->item($t));
        }
        return $order;
    }

    public function testDigitalOnlyOrder(): void
    {
        $order = $this->orderWith(ProductType::DIGITAL, ProductType::DIGITAL);
        $this->assertTrue($order->isDigitalOnly());
        $this->assertFalse($order->hasPhysicalItems());
    }

    public function testMixedOrder(): void
    {
        $order = $this->orderWith(ProductType::PHYSICAL, ProductType::DIGITAL);
        $this->assertFalse($order->isDigitalOnly());
        $this->assertTrue($order->hasPhysicalItems());
    }

    public function testPhysicalOnlyOrder(): void
    {
        $order = $this->orderWith(ProductType::PHYSICAL);
        $this->assertFalse($order->isDigitalOnly());
        $this->assertTrue($order->hasPhysicalItems());
    }

    public function testEmptyOrderIsNotDigitalOnly(): void
    {
        $this->assertFalse((new Order())->isDigitalOnly());
    }

    public function testOrderItemDigitalFlags(): void
    {
        $digital = $this->item(ProductType::DIGITAL);
        $this->assertTrue($digital->isDigital());
        $this->assertFalse($digital->requiresShipping());

        $physical = $this->item(ProductType::PHYSICAL);
        $this->assertFalse($physical->isDigital());
        $this->assertTrue($physical->requiresShipping());
    }
}
