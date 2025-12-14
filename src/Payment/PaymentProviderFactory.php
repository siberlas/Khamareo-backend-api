<?php
namespace App\Payment;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class PaymentProviderFactory
{
    private array $map = [];

     public function __construct(
       iterable $providers
    ) {
        foreach ($providers as $provider) {
            $this->map[$provider->getName()] = $provider;
        }
    }

    public function getProvider(string $name): PaymentProviderInterface
    {
        if (!isset($this->map[$name])) {
            throw new \InvalidArgumentException("Provider $name not found");
        }
        return $this->map[$name];
    }
}