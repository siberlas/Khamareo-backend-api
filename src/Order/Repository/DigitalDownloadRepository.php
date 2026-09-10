<?php

namespace App\Order\Repository;

use App\Order\Entity\DigitalDownload;
use App\Order\Entity\Order;
use App\Order\Entity\OrderItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DigitalDownload>
 */
class DigitalDownloadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DigitalDownload::class);
    }

    public function findOneByToken(string $token): ?DigitalDownload
    {
        return $this->findOneBy(['token' => $token]);
    }

    public function findOneByOrderItem(OrderItem $orderItem): ?DigitalDownload
    {
        return $this->findOneBy(['orderItem' => $orderItem]);
    }

    /** @return DigitalDownload[] */
    public function findByOrder(Order $order): array
    {
        return $this->findBy(['customerOrder' => $order], ['createdAt' => 'ASC']);
    }
}
