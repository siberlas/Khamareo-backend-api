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
     * Si countryCode est fourni, tente d'abord de trouver un tarif spécifique au pays,
     * puis fall back sur les tarifs sans country_code (tarifs génériques de zone).
     */
    public function findBestRate(CarrierMode $carrierMode, string $zone, int $weightGrams, ?string $countryCode = null): ?ShippingRate
    {
        $qb = $this->createQueryBuilder('r')
            ->andWhere('r.carrierMode = :carrierMode')
            ->andWhere('r.zone = :zone')
            ->andWhere(':w >= r.minWeightGrams')
            ->andWhere(':w <= r.maxWeightGrams')
            ->setParameter('carrierMode', $carrierMode)
            ->setParameter('zone', $zone)
            ->setParameter('w', $weightGrams)
            ->orderBy('r.maxWeightGrams', 'ASC')
            ->setMaxResults(1);

        if ($countryCode !== null) {
            $qb->andWhere('r.countryCode = :countryCode')
               ->setParameter('countryCode', strtoupper($countryCode));

            $result = $qb->getQuery()->getOneOrNullResult();
            if ($result !== null) {
                return $result;
            }

            // Fallback : tarif générique de zone (sans country_code)
            return $this->createQueryBuilder('r')
                ->andWhere('r.carrierMode = :carrierMode')
                ->andWhere('r.zone = :zone')
                ->andWhere(':w >= r.minWeightGrams')
                ->andWhere(':w <= r.maxWeightGrams')
                ->andWhere('r.countryCode IS NULL')
                ->setParameter('carrierMode', $carrierMode)
                ->setParameter('zone', $zone)
                ->setParameter('w', $weightGrams)
                ->orderBy('r.maxWeightGrams', 'ASC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        }

        return $qb->getQuery()->getOneOrNullResult();
    }
}
