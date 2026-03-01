<?php

namespace App\Shipping\Repository;

use App\Shipping\Entity\ShippingMode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShippingMode>
 */
class ShippingModeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShippingMode::class);
    }

    /**
     * Trouve un mode par son code
     */
    public function findByCode(string $code): ?ShippingMode
    {
        return $this->findOneBy(['code' => $code, 'isActive' => true]);
    }

    /**
     * Retourne tous les modes actifs
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('sm')
            ->where('sm.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('sm.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne les modes qui nécessitent un point de retrait
     */
    public function findRequiringPickupPoint(): array
    {
        return $this->createQueryBuilder('sm')
            ->where('sm.isActive = :active')
            ->andWhere('sm.requiresPickupPoint = :requires')
            ->setParameter('active', true)
            ->setParameter('requires', true)
            ->getQuery()
            ->getResult();
    }
}