<?php

namespace App\Shared\Repository;

use App\Shared\Entity\LaunchEmailQueue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LaunchEmailQueue>
 */
class LaunchEmailQueueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LaunchEmailQueue::class);
    }

    /**
     * Récupère un batch d'emails pending, triés par date de création (FIFO).
     *
     * @return LaunchEmailQueue[]
     */
    public function findPendingBatch(int $limit): array
    {
        return $this->createQueryBuilder('q')
            ->where('q.status = :status')
            ->setParameter('status', LaunchEmailQueue::STATUS_PENDING)
            ->orderBy('q.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->createQueryBuilder('q')
            ->select('COUNT(q.id)')
            ->where('q.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countTotal(): int
    {
        return (int) $this->createQueryBuilder('q')
            ->select('COUNT(q.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Retourne les stats de la queue.
     *
     * @return array{total: int, pending: int, sent: int, failed: int}
     */
    public function getStats(): array
    {
        return [
            'total'   => $this->countTotal(),
            'pending' => $this->countByStatus(LaunchEmailQueue::STATUS_PENDING),
            'sent'    => $this->countByStatus(LaunchEmailQueue::STATUS_SENT),
            'failed'  => $this->countByStatus(LaunchEmailQueue::STATUS_FAILED),
        ];
    }
}
