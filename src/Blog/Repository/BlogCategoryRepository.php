<?php

namespace App\Blog\Repository;

use App\Blog\Entity\BlogCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BlogCategory>
 */
class BlogCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlogCategory::class);
    }

    /**
     * Récupère toutes les catégories avec le nombre d'articles publiés
     * 
     * @return array
     */
    public function findAllWithPostCount(): array
    {
        return $this->createQueryBuilder('bc')
            ->select('bc', 'COUNT(bp.id) as postCount')
            ->leftJoin('bc.blogPosts', 'bp', 'WITH', 'bp.status = :status')
            ->setParameter('status', 'published')
            ->groupBy('bc.id')
            ->orderBy('bc.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les catégories qui ont au moins un article publié
     * 
     * @return BlogCategory[]
     */
    public function findWithPublishedPosts(): array
    {
        return $this->createQueryBuilder('bc')
            ->innerJoin('bc.blogPosts', 'bp')
            ->where('bp.status = :status')
            ->setParameter('status', 'published')
            ->groupBy('bc.id')
            ->orderBy('bc.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère une catégorie par slug
     * 
     * @param string $slug
     * @return BlogCategory|null
     */
    public function findOneBySlug(string $slug): ?BlogCategory
    {
        return $this->createQueryBuilder('bc')
            ->where('bc.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Compte le nombre d'articles par catégorie
     * 
     * @param BlogCategory $category
     * @param bool $publishedOnly
     * @return int
     */
    public function countPosts(BlogCategory $category, bool $publishedOnly = true): int
    {
        $qb = $this->createQueryBuilder('bc')
            ->select('COUNT(bp.id)')
            ->leftJoin('bc.blogPosts', 'bp')
            ->where('bc.id = :categoryId')
            ->setParameter('categoryId', $category->getId());

        if ($publishedOnly) {
            $qb->andWhere('bp.status = :status')
               ->setParameter('status', 'published');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}