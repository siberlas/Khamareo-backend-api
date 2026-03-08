<?php

namespace App\Shipping\Repository;

use App\Shipping\Entity\CarrierMode;
use App\Shipping\Entity\ShippingRate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ShippingRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShippingRate::class);
    }

    /**
     * Retourne le tarif correspondant à un CarrierMode, une zone et un poids en grammes.
     * Prend la plus petite tranche qui contient le poids.
     */
    public function findBestRate(CarrierMode $carrierMode, string $zone, int $weightGrams): ?ShippingRate
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.carrierMode = :carrierMode')
            ->andWhere('r.zone = :zone')
            ->andWhere(':w >= r.minWeightGrams')
            ->andWhere(':w <= r.maxWeightGrams')
            ->setParameter('carrierMode', $carrierMode)
            ->setParameter('zone', $zone)
            ->setParameter('w', $weightGrams)
            ->orderBy('r.maxWeightGrams', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
