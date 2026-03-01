<?php

namespace App\Shipping\Repository;

use App\Shipping\Entity\ParcelItem;
use App\Entity\Parcel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ParcelItem>
 */
class ParcelItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParcelItem::class);
    }

    /**
     * Trouve tous les items d'un colis
     */
    public function findByParcel(Parcel $parcel): array
    {
        return $this->createQueryBuilder('pi')
            ->where('pi.parcel = :parcel')
            ->setParameter('parcel', $parcel)
            ->getQuery()
            ->getResult();
    }
}

