<?php

namespace App\Service;

use App\Entity\Cart;
use App\Entity\Product;

class CartWeightCalculator
{
    /**
     * Calcule le poids total d’un panier (en kg).
     */
    public function getTotalWeightFromCart(Cart $cart): float
    {
        $total = 0.0;

        foreach ($cart->getItems() as $item) {
            $product = $item->getProduct();
            if (!$product instanceof Product) {
                continue;
            }
            $weight = (float) ($product->getWeight() ?? 0);
            $qty = (int) $item->getQuantity();
            $total += $weight * $qty;
        }

        return round($total, 3);
    }

    /**
     * Calcule le poids total depuis un tableau d’items (payload JSON).
     * Attendu: [['product' => '/api/products/{id}', 'quantity' => 2], ...]
     */
    public function getTotalWeightFromItems(array $items, callable $findProductBySlug): float
    {
        $total = 0.0;

        foreach ($items as $row) {
            // On gère les deux formats possibles : "slug" ou "product"
            $productSlug = $row['slug'] ?? basename((string) ($row['product'] ?? ''));
            $qty = (int) ($row['quantity'] ?? 1);

            if (!$productSlug || $qty <= 0) {
                continue;
            }

            $product = $findProductBySlug($productSlug);

            if ($product instanceof Product && $product->getWeight() !== null) {
                $total += ((float) $product->getWeight()) * $qty;
            }
        }

        return round($total, 3);
    }
}
