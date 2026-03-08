<?php

namespace App\Payment\Controller;

use App\Order\Entity\Order;
use App\Payment\Entity\Payment;
use App\Shared\Enum\OrderStatus;
use App\Shared\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
class OrderByPaymentIntentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerService $mailerService,
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

        // Fallback: envoyer l'email de confirmation si le webhook Stripe ne l'a pas encore fait
        if ($order->getStatus() === OrderStatus::PAID && !$order->isConfirmationEmailSent()) {
            $this->mailerService->sendOrderConfirmation($order);
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
