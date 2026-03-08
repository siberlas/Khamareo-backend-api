<?php

namespace App\Order\Repository;

use App\Order\Entity\Order;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    public function countOrdersByPeriod(?\DateTimeImmutable $since = null): int
    {
        $qb = $this->createQueryBuilder('o')->select('COUNT(o.id)');
        if ($since) {
            $qb->andWhere('o.createdAt >= :since')->setParameter('since', $since);
        }
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function getTotalRevenue(?\DateTimeImmutable $since = null): float
    {
        $qb = $this->createQueryBuilder('o')
            ->select('COALESCE(SUM(o.totalAmount), 0)')
            ->andWhere("o.paymentStatus = 'paid'");
        if ($since) {
            $qb->andWhere('o.createdAt >= :since')->setParameter('since', $since);
        }
        return round((float) $qb->getQuery()->getSingleScalarResult(), 2);
    }

    public function getAverageOrderValue(?\DateTimeImmutable $since = null): float
    {
        $qb = $this->createQueryBuilder('o')
            ->select('COALESCE(AVG(o.totalAmount), 0)')
            ->andWhere("o.paymentStatus = 'paid'");
        if ($since) {
            $qb->andWhere('o.createdAt >= :since')->setParameter('since', $since);
        }
        return round((float) $qb->getQuery()->getSingleScalarResult(), 2);
    }

    public function countOrdersByStatus(?\DateTimeImmutable $since = null): array
    {
        $qb = $this->createQueryBuilder('o')
            ->select('o.status, COUNT(o.id) AS cnt')
            ->groupBy('o.status');
        if ($since) {
            $qb->andWhere('o.createdAt >= :since')->setParameter('since', $since);
        }
        $rows = $qb->getQuery()->getArrayResult();
        $result = [];
        foreach ($rows as $row) {
            $status = $row['status'] instanceof \BackedEnum ? $row['status']->value : (string) $row['status'];
            $result[$status] = (int) $row['cnt'];
        }
        return $result;
    }

    public function getRevenueByStatus(?\DateTimeImmutable $since = null): array
    {
        $qb = $this->createQueryBuilder('o')
            ->select('o.status, COALESCE(SUM(o.totalAmount), 0) AS revenue')
            ->groupBy('o.status');
        if ($since) {
            $qb->andWhere('o.createdAt >= :since')->setParameter('since', $since);
        }
        $rows = $qb->getQuery()->getArrayResult();
        $result = [];
        foreach ($rows as $row) {
            $status = $row['status'] instanceof \BackedEnum ? $row['status']->value : (string) $row['status'];
            $result[$status] = round((float) $row['revenue'], 2);
        }
        return $result;
    }

    public function hasVerifiedPurchase(string $email, \App\Catalog\Entity\Product $product): bool
    {
        $conn = $this->getEntityManager()->getConnection();
        $result = $conn->executeQuery(
            'SELECT COUNT(o.id)
             FROM "order" o
             JOIN order_item oi ON oi.customer_order_id = o.id
             WHERE (o.guest_email = :email
                OR o.owner_id IN (SELECT id FROM "user" WHERE email = :email))
             AND oi.product_id = :productId
             AND o.payment_status = \'paid\'',
            ['email' => $email, 'productId' => $product->getId()->toRfc4122()]
        )->fetchOne();
        return (int) $result > 0;
    }
}
