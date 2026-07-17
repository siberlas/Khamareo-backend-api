<?php

namespace App\Marketing\Repository;

use App\Marketing\Entity\PromoCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PromoCode>
 */
class PromoCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PromoCode::class);
    }

    //    /**
    //     * @return PromoCode[] Returns an array of PromoCode objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('p.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?PromoCode
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }

    /**
     * Code promo actif, non utilisé, non expiré, associé à cet email —
     * utilisé pour éviter de générer un doublon (ex: relance panier J+3
     * réutilisant un reliquat du Segment 3).
     */
    public function findActiveUnusedByEmail(string $email): ?PromoCode
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.email = :email')
            ->andWhere('p.isUsed = false')
            ->andWhere('p.isActive = true')
            ->andWhere('p.expiresAt > :now')
            ->setParameter('email', $email)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('p.expiresAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
