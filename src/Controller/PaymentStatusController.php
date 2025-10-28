<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\Payment;
use App\Enum\OrderStatus;
use App\Enum\PaymentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[AsController]
class PaymentStatusController extends AbstractController
{
    public function __invoke(string $id, Request $request, EntityManagerInterface $em): Payment
    {
        $payment = $em->getRepository(Payment::class)->find($id);
        if (!$payment) {
            throw $this->createNotFoundException("Paiement introuvable");
        }

        $data = json_decode($request->getContent(), true);
        $status = $data['status'] ?? null;

        if (!in_array($status, array_column(PaymentStatus::cases(), 'value'))) {
            throw new BadRequestHttpException("Statut de paiement invalide");
        }

        $newStatus = PaymentStatus::from($status);
        $payment->setStatus($newStatus);

        // 🧩 Mise à jour de la commande liée
        $order = $payment->getOrder();

        match ($newStatus) {
            PaymentStatus::SUCCEEDED => $order->setStatus(OrderStatus::PAID),
            PaymentStatus::FAILED => $order->setStatus(OrderStatus::CANCELLED),
            PaymentStatus::REFUNDED => $order->setStatus(OrderStatus::REFUNDED),
            default => null,
        };

        $em->flush();

        return $payment;
    }
}
