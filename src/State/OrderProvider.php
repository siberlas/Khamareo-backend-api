<?php
// src/State/OrderProvider.php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

final class OrderProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable|object|null
    {
        $user = $this->security->getUser();
        $repo = $this->em->getRepository(Order::class);

        $qb = $repo->createQueryBuilder('o');

        // 🔒 Filtre propriétaire pour un user non admin
        if ($user && !$this->security->isGranted('ROLE_ADMIN')) {
            $qb
                ->andWhere('o.owner = :owner')
                ->setParameter('owner', $user);
        }

        // 🔎 Filtre par numéro de commande si présent
        $filters = $context['filters'] ?? [];
        if (!empty($filters['orderNumber'])) {
            $qb
                ->andWhere('o.orderNumber = :orderNumber')
                ->setParameter('orderNumber', $filters['orderNumber']);
        }

        $qb->orderBy('o.createdAt', 'DESC');

        return $qb->getQuery()->getResult();
    }
}
