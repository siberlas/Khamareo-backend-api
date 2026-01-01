<?php
// ...
namespace App\Shipping\Repository;

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
     * Retourne le tarif correspondant à un provider, une zone et un poids.
     * Prend la plus petite tranche qui contient le poids.
     */
    public function findBestRate(string $provider, string $zone, float $weight): ?ShippingRate
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.provider = :provider')
            ->andWhere('r.zone = :zone')
            ->andWhere(':w >= r.minWeight')
            ->andWhere(':w <= r.maxWeight')
            ->setParameter('provider', $provider)
            ->setParameter('zone', $zone)
            ->setParameter('w', $weight)
            ->orderBy('r.maxWeight', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
