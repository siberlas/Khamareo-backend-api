<?php

namespace App\Blog\Repository;

use App\Blog\Entity\BlogPost;
use App\Blog\Entity\BlogCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BlogPost>
 */
class BlogPostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlogPost::class);
    }

    /**
     * Récupère tous les articles publiés
     * Triés par date de publication décroissante
     * 
     * @return BlogPost[]
     */
    public function findPublished(?int $limit = null): array  // ← ?int au lieu de int
    {
        $qb = $this->createQueryBuilder('bp')
            ->where('bp.status = :status')
            ->setParameter('status', 'published')
            ->orderBy('bp.publishedAt', 'DESC');

        if ($limit) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Récupère les articles à la une (featured)
     * 
     * @param int $limit Nombre d'articles à récupérer
     * @return BlogPost[]
     */
    public function findFeatured(int $limit = 3): array
    {
        return $this->createQueryBuilder('bp')
            ->where('bp.status = :status')
            ->andWhere('bp.isFeatured = :featured')
            ->setParameter('status', 'published')
            ->setParameter('featured', true)
            ->orderBy('bp.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les articles par catégorie
     * 
     * @param BlogCategory $category
     * @param bool $publishedOnly Ne récupérer que les publiés
     * @return BlogPost[]
     */
    public function findByCategory(BlogCategory $category, bool $publishedOnly = true): array
    {
        $qb = $this->createQueryBuilder('bp')
            ->where('bp.category = :category')
            ->setParameter('category', $category)
            ->orderBy('bp.publishedAt', 'DESC');

        if ($publishedOnly) {
            $qb->andWhere('bp.status = :status')
               ->setParameter('status', 'published');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Recherche d'articles par terme de recherche
     * Cherche dans le titre, l'excerpt et le contenu
     * 
     * @param string $searchTerm Terme de recherche
     * @param bool $publishedOnly Ne chercher que dans les publiés
     * @return BlogPost[]
     */
    public function search(string $searchTerm, bool $publishedOnly = true): array
    {
        $qb = $this->createQueryBuilder('bp')
            ->where('bp.title LIKE :search')
            ->orWhere('bp.excerpt LIKE :search')
            ->orWhere('bp.content LIKE :search')
            ->setParameter('search', '%' . $searchTerm . '%')
            ->orderBy('bp.publishedAt', 'DESC');

        if ($publishedOnly) {
            $qb->andWhere('bp.status = :status')
               ->setParameter('status', 'published');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Récupère les articles récents (derniers N jours)
     * 
     * @param int $days Nombre de jours
     * @return BlogPost[]
     */
    public function findRecent(int $days = 7): array
    {
        $date = new \DateTimeImmutable(sprintf('-%d days', $days));

        return $this->createQueryBuilder('bp')
            ->where('bp.status = :status')
            ->andWhere('bp.publishedAt >= :date')
            ->setParameter('status', 'published')
            ->setParameter('date', $date)
            ->orderBy('bp.publishedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les articles similaires (même catégorie)
     * 
     * @param BlogPost $blogPost Article de référence
     * @param int $limit Nombre d'articles similaires
     * @return BlogPost[]
     */
    public function findSimilar(BlogPost $blogPost, int $limit = 3): array
    {
        $qb = $this->createQueryBuilder('bp')
            ->where('bp.status = :status')
            ->andWhere('bp.id != :currentId')
            ->setParameter('status', 'published')
            ->setParameter('currentId', $blogPost->getId())
            ->orderBy('bp.publishedAt', 'DESC')
            ->setMaxResults($limit);

        // Si l'article a une catégorie, filtrer par catégorie
        if ($blogPost->getCategory()) {
            $qb->andWhere('bp.category = :category')
               ->setParameter('category', $blogPost->getCategory());
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Compte les articles par statut
     * 
     * @return array ['draft' => 5, 'published' => 20]
     */
    public function countByStatus(): array
    {
        $result = $this->createQueryBuilder('bp')
            ->select('bp.status, COUNT(bp.id) as count')
            ->groupBy('bp.status')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($result as $row) {
            $counts[$row['status']] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Récupère les articles par auteur
     * 
     * @param mixed $authorId ID de l'auteur
     * @param bool $publishedOnly Ne récupérer que les publiés
     * @return BlogPost[]
     */
    public function findByAuthor(mixed $authorId, bool $publishedOnly = true): array  // ← mixed explicite
    {
        $qb = $this->createQueryBuilder('bp')
            ->where('bp.author = :authorId')
            ->setParameter('authorId', $authorId)
            ->orderBy('bp.createdAt', 'DESC');

        if ($publishedOnly) {
            $qb->andWhere('bp.status = :status')
               ->setParameter('status', 'published');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Récupère les articles par slug (unique)
     * 
     * @param string $slug
     * @return BlogPost|null
     */
    public function findOneBySlug(string $slug): ?BlogPost
    {
        return $this->createQueryBuilder('bp')
            ->where('bp.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Récupère les statistiques globales du blog
     * 
     * @return array
     */
    public function getStatistics(): array
    {
        $totalPublished = $this->count(['status' => 'published']);
        $totalDrafts = $this->count(['status' => 'draft']);
        $totalFeatured = $this->count(['isFeatured' => true, 'status' => 'published']);

        // Article le plus récent
        $lastPublished = $this->findOneBy(
            ['status' => 'published'],
            ['publishedAt' => 'DESC']
        );

        return [
            'totalPublished' => $totalPublished,
            'totalDrafts' => $totalDrafts,
            'totalFeatured' => $totalFeatured,
            'totalArticles' => $totalPublished + $totalDrafts,
            'lastPublishedAt' => $lastPublished?->getPublishedAt(),
        ];
    }
}