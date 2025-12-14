<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\Payment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
class OrderByPaymentIntentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    #[Route('/api/orders/by-payment-intent/{piId}', name: 'order_by_payment_intent', methods: ['GET'])]
    public function __invoke(string $piId): JsonResponse
    {
        /** @var Payment|null $payment */
        $payment = $this->em->getRepository(Payment::class)
            ->findOneBy(['providerPaymentId' => $piId]);

        if (!$payment) {
            return $this->json(['error' => 'PaymentIntent not found'], 404);
        }

        $order = $payment->getOrder();

        if (!$order) {
            return $this->json(['error' => 'Order not found'], 404);
        }

        return $this->json([
            'orderNumber' => $order->getOrderNumber(),
            'status' => $order->getStatus()->value,
            'total' => $order->getTotalAmount(),
            'currency' => $order->getCurrency(),
            'shippingCost' => $order->getShippingCost(),
            'createdAt' => $order->getCreatedAt()?->format('c'),
        ]);
    }
}
