<?php

namespace App\Shipping\Repository;

use App\Shipping\Entity\Carrier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Carrier>
 */
class CarrierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Carrier::class);
    }

    /**
     * Trouve un transporteur par son code
     */
    public function findByCode(string $code): ?Carrier
    {
        return $this->findOneBy(['code' => $code, 'isActive' => true]);
    }

    /**
     * Retourne tous les transporteurs actifs
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les transporteurs qui peuvent gérer un poids donné
     */
    public function findByWeightCapacity(int $weightGrams): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.isActive = :active')
            ->andWhere('c.minWeightGrams <= :weight')
            ->andWhere('c.maxWeightGrams >= :weight')
            ->setParameter('active', true)
            ->setParameter('weight', $weightGrams)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}