<?php

namespace App\Payment\Service;

use App\Order\Entity\Order;
use App\Payment\Entity\Payment;
use App\Shared\Enum\PaymentStatus;
use Stripe\PaymentIntent;

class PaymentService
{
    /**
     * Crée un Payment à partir d'un Order et d'un PaymentIntent Stripe (status = PAID).
     * Ne fait pas de flush : à gérer dans le contrôleur.
     */
    public function createPaymentForOrderFromStripeIntent(
        Order $order,
        PaymentIntent $pi
    ): Payment {
        $payment = new Payment();
        $payment->setOrder($order);
        $payment->setProvider('stripe');
        $payment->setProviderPaymentId($pi->id);
        $payment->setStatus(PaymentStatus::PAID);
        $payment->setAmount($pi->amount_received / 100);
        $payment->setPaidAt(new \DateTimeImmutable());

        return $payment;
    }

    /**
     * Met à jour un Payment existant à partir d'un PaymentIntent Stripe (status = PAID).
     */
    public function markExistingPaymentPaid(
        Payment $payment,
        PaymentIntent $pi
    ): void {
        $payment->setStatus(PaymentStatus::PAID);
        $payment->setAmount($pi->amount_received / 100);
        $payment->setPaidAt(new \DateTimeImmutable());
    }
}
