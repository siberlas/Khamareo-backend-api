<?php

namespace App\Contact\Repository;

use App\Contact\Entity\ContactConversation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ContactConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactConversation::class);
    }

    public function findByEmail(string $email): ?ContactConversation
    {
        return $this->findOneBy(['email' => $email]);
    }

    public function findAllOrderedByLastMessage(int $limit = 20, int $offset = 0): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.lastMessageAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countNew(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.hasNew = true')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
