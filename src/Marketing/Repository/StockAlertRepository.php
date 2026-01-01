<?php

namespace App\Marketing\Repository;

use App\Marketing\Entity\StockAlert;
use App\User\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class StockAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockAlert::class);
    }

    /**
     * Compte les alertes actives (non notifiées) d'un utilisateur
     */
    public function countActiveAlerts(User $owner): int
    {
        return $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.owner = :owner')  // ✅ Changé de 'a.user' à 'a.owner'
            ->andWhere('a.notified = false')
            ->setParameter('owner', $owner)  // ✅ Changé de 'user' à 'owner'
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Trouve les alertes à notifier (produits de nouveau en stock)
     */
    public function findAlertsToNotify(): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.product', 'p')
            ->where('a.notified = false')
            ->andWhere('p.stock > 0')
            ->getQuery()
            ->getResult();
    }

    /**
     * Supprime les alertes notifiées de plus de X jours
     */
    public function deleteOldNotifiedAlerts(int $days = 30): int
    {
        $date = new \DateTimeImmutable("-{$days} days");

        return $this->createQueryBuilder('a')
            ->delete()
            ->where('a.notified = true')
            ->andWhere('a.notifiedAt < :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->execute();
    }
}