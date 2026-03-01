<?php

namespace App\Admin\Controller;

use App\Admin\Dto\UpdateOrderStatusInput;
use App\Order\Entity\Order; // adapte si ton namespace est App\Entity\Order
use App\Shared\Enum\OrderStatus; // adapte si ton enum est App\Enum\OrderStatus
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;


#[AsController]
final class AdminUpdateOrderStatusController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(Order $order, UpdateOrderStatusInput $input): Order
    {
        $raw = $input->status;

        if (!$raw) {
            throw new BadRequestHttpException('Missing "status".');
        }

        // IMPORTANT: tes valeurs d'enum sont en minuscules (pending/paid/preparing...)
        // Donc le front doit envoyer ces valeurs-là.
        try {
            $newStatus = OrderStatus::from($raw);
        } catch (\ValueError) {
            $allowed = array_map(static fn(OrderStatus $s) => $s->value, OrderStatus::cases());
            throw new BadRequestHttpException(sprintf(
                'Invalid status "%s". Allowed: %s',
                $raw,
                implode(', ', $allowed)
            ));
        }

        // Règles métier (exemples, adapte à ton enum)
        if (method_exists($order->getStatus(), 'isFinal') && $order->getStatus()->isFinal()) {
            throw new BadRequestHttpException('Order is final and cannot be updated.');
        }
        if (method_exists($order->getStatus(), 'canBeModified') && !$order->getStatus()->canBeModified()) {
            throw new BadRequestHttpException('Order cannot be modified in its current status.');
        }

        $order->setStatus($newStatus);

        $this->em->flush();

        return $order;
    }
}
