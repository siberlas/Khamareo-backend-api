<?php

namespace App\Payment\Controller;

use App\Payment\Repository\PaymentRepository;
use App\Order\Repository\OrderRepository;
use App\Payment\Provider\StripePaymentProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
class StripePaymentStatusController extends AbstractController
{
    public function __construct(
        private readonly StripePaymentProvider $stripe,
        private readonly PaymentRepository $paymentRepo,
        private readonly OrderRepository $orderRepo,
    ) {}

    #[Route('/api/stripe/payment-status/{piId}', name: 'stripe_payment_status', methods: ['GET'])]
    public function __invoke(string $piId): JsonResponse
    {
        // 1️⃣ Chercher un paiement interne lié à ce PI
        $payment = $this->paymentRepo->findOneBy(['providerPaymentId' => $piId]);

        if ($payment && $payment->getOrder()) {
            return $this->json([
                'status'      => 'paid_order_created',
                'orderId'     => $payment->getOrder()->getId(),
                'orderNumber' => $payment->getOrder()->getOrderNumber(),
            ]);
        }

        // 2️⃣ Vérifier Stripe directement
        try {
            $pi = $this->stripe->retrievePaymentIntent($piId);
        } catch (\Throwable) {
            return $this->json(['status' => 'not_found'], 404);
        }

        switch ($pi->status) {

            case 'succeeded':
                return $this->json([
                    'status'  => 'paid_order_missing',
                    'message' => 'Paiement validé mais commande pas encore créée.',
                ]);

            case 'processing':
                return $this->json(['status' => 'processing']);

            case 'requires_action':
            case 'requires_payment_method':
                return $this->json(['status' => 'requires_action']);

            default:
                return $this->json([
                    'status'  => 'error',
                    'message' => "Unexpected Stripe status: {$pi->status}",
                ], 500);
        }
    }
}
