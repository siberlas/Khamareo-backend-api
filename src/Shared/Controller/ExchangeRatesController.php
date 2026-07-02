<?php

namespace App\Shared\Controller;

use App\Shared\Service\StripeExchangeRateService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
class ExchangeRatesController extends AbstractController
{
    public function __construct(
        private readonly StripeExchangeRateService $exchangeRateService,
    ) {}

    #[Route('/api/exchange-rates', name: 'exchange_rates', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return $this->json($this->exchangeRateService->getRates());
    }
}
