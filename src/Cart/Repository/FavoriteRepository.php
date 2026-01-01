<?php

namespace App\Cart\Repository;

use App\Cart\Entity\Favorite;
use App\User\Entity\User;
use App\Catalog\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class FavoriteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Favorite::class);
    }

    /**
     * Vérifie si un produit est déjà dans les favoris d'un utilisateur
     */
    public function isFavorite(User $owner, Product $product): bool
    {
        return $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.owner = :owner')
            ->andWhere('f.product = :product')
            ->setParameter('owner', $owner)
            ->setParameter('product', $product)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Récupère les IDs des produits favoris d'un utilisateur
     */
    public function getFavoriteProductIds(User $owner): array
    {
        $results = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.product)')
            ->where('f.owner = :owner')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getScalarResult();

        return array_column($results, 1);
    }

    /**
     * Compte les favoris d'un utilisateur
     */
    public function countByOwner(User $owner): int
    {
        return $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.owner = :owner')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getSingleScalarResult();
    }
}