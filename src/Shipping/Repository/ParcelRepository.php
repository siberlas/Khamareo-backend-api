<?php

namespace App\Shipping\Repository;

use App\Shipping\Entity\Parcel;
use App\Order\Entity\Order; 
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Parcel>
 */
class ParcelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Parcel::class);
    }

    /**
     * Trouve tous les colis d'une commande
     */
    public function findByOrder(Order $order): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.order = :order')
            ->setParameter('order', $order)
            ->orderBy('p.parcelNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve un colis par numéro de suivi
     */
    public function findByTrackingNumber(string $trackingNumber): ?Parcel
    {
        return $this->findOneBy(['trackingNumber' => $trackingNumber]);
    }

    /**
     * Compte le nombre de colis d'une commande
     */
    public function countByOrder(Order $order): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.order = :order')
            ->setParameter('order', $order)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Trouve les colis par statut
     */
    public function findByStatus(string $status): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.status = :status')
            ->setParameter('status', $status)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve le prochain numéro de colis pour une commande
     */
    public function getNextParcelNumber(Order $order): int
    {
        $result = $this->createQueryBuilder('p')
            ->select('MAX(p.parcelNumber)')
            ->where('p.order = :order')
            ->setParameter('order', $order)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $result) + 1;
    }
}