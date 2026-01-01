<?php

namespace App\Payment\Controller;

use App\Order\Entity\Order;
use App\Order\Entity\OrderItem;
use App\Payment\Entity\Payment;
use App\Shared\Enum\OrderStatus;
use App\Cart\Repository\CartRepository;
use App\Order\Repository\OrderRepository;
use App\Payment\Repository\PaymentRepository;
use App\Payment\Service\PaymentService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Stripe;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
class PaymentRepairController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private CartRepository $cartRepo,
        private OrderRepository $orderRepo,
        private PaymentRepository $paymentRepo,
        private PaymentService $paymentService,
        private LoggerInterface $logger
    ) {}

    #[Route('/api/payment/repair/{piId}', name: 'payment_repair', methods: ['GET'])]
    public function __invoke(string $piId): JsonResponse
    {
        Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

        try {
            $pi = \Stripe\PaymentIntent::retrieve($piId);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'payment_intent_not_found'], 404);
        }

        if ($pi->status !== 'succeeded') {
            return new JsonResponse(['error' => 'payment_not_succeeded'], 400);
        }

        // Payment déjà créé ?
        $existingPayment = $this->paymentRepo->findOneBy(['providerPaymentId' => $piId]);
        if ($existingPayment) {
            return new JsonResponse([
                'status'  => 'order_exists',
                'orderId' => $existingPayment->getOrder()->getId(),
            ], 200);
        }

        $cartId = $pi->metadata['cart_id'] ?? null;
        if (!$cartId) {
            return new JsonResponse(['error' => 'metadata_cart_missing'], 400);
        }

        $cart = $this->cartRepo->find($cartId);
        if (!$cart) {
            return new JsonResponse(['error' => 'cart_not_found'], 404);
        }

        // Créer Order à partir du Cart
        $order = new Order();
        $order->setOwner($cart->getOwner());
        $order->setTotalAmount($cart->getTotalAmount());
        $order->setStatus(OrderStatus::PAID);

        foreach ($cart->getItems() as $item) {
            $orderItem = new OrderItem();
            $orderItem->setCustomerOrder($order);
            $orderItem->setProduct($item->getProduct());
            $orderItem->setQuantity($item->getQuantity());
            $orderItem->setUnitPrice($item->getUnitPrice());
            $this->em->persist($orderItem);
        }

        // Créer Payment via PaymentService
        $payment = $this->paymentService->createPaymentForOrderFromStripeIntent($order, $pi);

        $this->em->persist($order);
        $this->em->persist($payment);
        $this->em->flush();

        return new JsonResponse([
            'status'  => 'order_repaired',
            'orderId' => $order->getId(),
        ], 200);
    }
}
