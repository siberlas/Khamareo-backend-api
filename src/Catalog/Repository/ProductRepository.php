<?php

namespace App\Catalog\Repository;

use App\Catalog\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.isDeleted = false')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countLowStock(int $threshold = 10): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.isDeleted = false')
            ->andWhere('p.stock > 0')
            ->andWhere('p.stock <= :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countOutOfStock(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.isDeleted = false')
            ->andWhere('p.stock <= 0')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getAveragePrice(): float
    {
        $result = $this->createQueryBuilder('p')
            ->select('AVG(p.price)')
            ->andWhere('p.isDeleted = false')
            ->andWhere('p.isEnabled = true')
            ->getQuery()
            ->getSingleScalarResult();

        return round((float) $result, 2);
    }

    public function getTotalInventoryValue(): float
    {
        $result = $this->createQueryBuilder('p')
            ->select('SUM(p.price * p.stock)')
            ->andWhere('p.isDeleted = false')
            ->getQuery()
            ->getSingleScalarResult();

        return round((float) $result, 2);
    }

    public function getTopSelling(int $limit = 5): array
    {
        return $this->createQueryBuilder('p')
            ->select('p.id, p.name, p.slug, p.price, p.stock, p.rating, p.reviewsCount')
            ->andWhere('p.isDeleted = false')
            ->andWhere('p.isEnabled = true')
            ->orderBy('p.reviewsCount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }

    public function getRecentlyAdded(int $limit = 5): array
    {
        return $this->createQueryBuilder('p')
            ->select('p.id, p.name, p.slug, p.price, p.stock, p.createdAt')
            ->andWhere('p.isDeleted = false')
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }

    public function findLowStockOptimized(int $threshold = 10): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT p.id, p.name, p.slug, p.price, p.stock, p.is_enabled
            FROM product p
            WHERE p.is_deleted = false
              AND p.stock > 0
              AND p.stock <= :threshold
            ORDER BY p.stock ASC
        ';

        return $conn->executeQuery($sql, ['threshold' => $threshold])->fetchAllAssociative();
    }

    public function findOutOfStockOptimized(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT p.id, p.name, p.slug, p.price, p.stock, p.is_enabled
            FROM product p
            WHERE p.is_deleted = false
              AND p.stock <= 0
            ORDER BY p.name ASC
        ';

        return $conn->executeQuery($sql)->fetchAllAssociative();
    }

    public function findDeleted(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.isDeleted = true')
            ->orderBy('p.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneBySlugIncludingDeleted(string $slug): ?Product
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countDeleted(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.isDeleted = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findDeletedNotUsedInOrders(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT p.id
            FROM product p
            WHERE p.is_deleted = true
              AND p.id NOT IN (
                  SELECT DISTINCT oi.product_id
                  FROM order_item oi
                  WHERE oi.product_id IS NOT NULL
              )
        ';

        $rows = $conn->executeQuery($sql)->fetchAllAssociative();
        $ids = array_column($rows, 'id');

        if (empty($ids)) {
            return [];
        }

        return $this->createQueryBuilder('p')
            ->andWhere('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }
}
