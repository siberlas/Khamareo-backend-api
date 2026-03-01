<?php
namespace App\Payment\Response;

class PaymentResponse
{
    public function __construct(
        public readonly string $provider,
        public readonly string $paymentId,
        public readonly string $clientSecret,
        public readonly ?string $redirectUrl = null,
    ) {}
}
