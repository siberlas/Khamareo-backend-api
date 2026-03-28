<?php
// src/State/ProductPriceProvider.php

namespace App\Catalog\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Catalog\Entity\Product;
use App\Shared\Entity\Currency;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class ProductPriceProvider implements ProviderInterface
{
    public function __construct(
        private ProviderInterface $decorated,
        private RequestStack $requestStack,
        private EntityManagerInterface $entityManager
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $result = $this->decorated->provide($operation, $uriVariables, $context);

        if (!$result) {
            return $result;
        }

        $request = $this->requestStack->getCurrentRequest();
        $currencyCode = $request?->query->get('currency', 'EUR');

        // Récupérer l'objet Currency pour l'exposer dans l'API
        $currency = $this->entityManager->getRepository(Currency::class)->findOneBy(['code' => $currencyCode]);
        if (!$currency) {
            $currency = $this->entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);
        }

        if ($result instanceof Product) {
            $this->applyPriceForCurrency($result, $currencyCode, $currency);
            return $result;
        }

        if (is_iterable($result)) {
            foreach ($result as $product) {
                if ($product instanceof Product) {
                    $this->applyPriceForCurrency($product, $currencyCode, $currency);
                }
            }
        }

        return $result;
    }

    private function applyPriceForCurrency(Product $product, string $currencyCode, ?Currency $currency): void
    {
        // Tente d'utiliser product_price si disponible, sinon garde product.price
        $productPrice = $product->getPriceForCurrency($currencyCode);

        if (!$productPrice) {
            $productPrice = $product->getPriceForCurrency('EUR');
        }

        if ($productPrice) {
            $product->setPrice($productPrice->getPrice());
            $product->setOriginalPrice($productPrice->getOriginalPrice());
        }

        // Toujours setter la devise
        if ($currency) {
            $product->setCurrency($currency);
        }
    }
}