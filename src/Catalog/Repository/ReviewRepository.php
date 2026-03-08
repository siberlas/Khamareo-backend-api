<?php

namespace App\Catalog\Repository;

use App\Catalog\Entity\Product;
use App\Catalog\Entity\Review;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Review>
 */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    /**
     * @return array{count: int, avg: float|null}
     */
    public function getVerifiedStats(Product $product): array
    {
        $result = $this->createQueryBuilder('r')
            ->select('COUNT(r.id) AS cnt, AVG(r.rating) AS avg_rating')
            ->where('r.product = :product')
            ->andWhere('r.isVerified = true')
            ->setParameter('product', $product)
            ->getQuery()
            ->getSingleResult();

        return [
            'count' => (int) $result['cnt'],
            'avg' => $result['avg_rating'] !== null ? (float) $result['avg_rating'] : null,
        ];
    }
}
