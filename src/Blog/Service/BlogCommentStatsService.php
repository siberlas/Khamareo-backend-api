<?php

namespace App\Blog\Service;

use App\Blog\Entity\BlogPost;
use App\Blog\Repository\BlogCommentRepository;
use Doctrine\ORM\EntityManagerInterface;

class BlogCommentStatsService
{
    public function __construct(
        private BlogCommentRepository $repo,
        private EntityManagerInterface $em,
    ) {}

    public function recalculate(BlogPost $post): void
    {
        $result = $this->repo->createQueryBuilder('c')
            ->select('COUNT(c.id) as cnt, AVG(c.rating) as avg')
            ->where('c.blogPost = :post')
            ->andWhere('c.isApproved = true')
            ->setParameter('post', $post)
            ->getQuery()
            ->getSingleResult();

        $post->setCommentCount((int) ($result['cnt'] ?? 0));
        $post->setAverageRating($result['avg'] ? (float) $result['avg'] : null);

        $this->em->flush();
    }
}
