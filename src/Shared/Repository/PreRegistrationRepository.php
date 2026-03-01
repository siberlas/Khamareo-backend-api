<?php

namespace App\Shared\Repository;

use App\Shared\Entity\PreRegistration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PreRegistrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PreRegistration::class);
    }

    public function findByEmail(string $email): ?PreRegistration
    {
        return $this->findOneBy(['email' => $email]);
    }

    /**
     * @return PreRegistration[]
     */
    public function findAllOrderedByDate(int $page = 1, int $limit = 50): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult(($page - 1) * $limit)
            ->getQuery()
            ->getResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
