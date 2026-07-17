<?php

namespace App\Marketing\Repository;

use App\Marketing\Entity\EmailSendLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailSendLog>
 */
class EmailSendLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailSendLog::class);
    }

    /**
     * Un contact a-t-il déjà reçu un email automatisé (tous segments confondus)
     * aujourd'hui ? Sert à la priorité "Segment 1 seul envoyé en cas de conflit".
     */
    public function hasSentToday(string $email): bool
    {
        $startOfDay = new \DateTimeImmutable('today');

        $count = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.email = :email')
            ->andWhere('l.sentAt >= :startOfDay')
            ->setParameter('email', $email)
            ->setParameter('startOfDay', $startOfDay)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
