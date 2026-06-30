<?php

namespace App\Blog\Repository;

use App\Blog\Entity\BlogComment;
use App\Blog\Entity\BlogPost;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BlogComment>
 */
class BlogCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlogComment::class);
    }

    public function countPending(): int
    {
        return $this->count(['isApproved' => false]);
    }

    public function findByPostApproved(BlogPost $post): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.blogPost = :post')
            ->andWhere('c.isApproved = true')
            ->setParameter('post', $post)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
