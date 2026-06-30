<?php

namespace App\Blog\Controller;

use App\Blog\Entity\BlogComment;
use App\Blog\Repository\BlogCommentRepository;
use App\Blog\Service\BlogCommentStatsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[Route('/api/admin/blog-comments')]
#[IsGranted('ROLE_ADMIN')]
class AdminBlogCommentController extends AbstractController
{
    public function __construct(
        private BlogCommentRepository $repo,
        private EntityManagerInterface $em,
        private BlogCommentStatsService $stats,
    ) {}

    #[Route('', name: 'admin_blog_comments_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $isApproved = $request->query->get('isApproved');
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $qb = $this->repo->createQueryBuilder('c')
            ->leftJoin('c.blogPost', 'bp')
            ->addSelect('bp')
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($isApproved !== null) {
            $qb->andWhere('c.isApproved = :approved')
               ->setParameter('approved', $isApproved === 'true' || $isApproved === '1');
        }

        $comments = $qb->getQuery()->getResult();
        $total = $this->repo->count($isApproved !== null ? ['isApproved' => ($isApproved === 'true' || $isApproved === '1')] : []);

        return $this->json([
            'data' => array_map(fn(BlogComment $c) => $this->serialize($c), $comments),
            'total' => $total,
            'page' => $page,
            'pending' => $this->repo->countPending(),
        ]);
    }

    #[Route('/{id}/approve', name: 'admin_blog_comment_approve', methods: ['POST'])]
    public function approve(BlogComment $comment): JsonResponse
    {
        $comment->setIsApproved(true);
        $this->em->flush();
        $this->stats->recalculate($comment->getBlogPost());

        return $this->json(['success' => true, 'comment' => $this->serialize($comment)]);
    }

    #[Route('/{id}/reject', name: 'admin_blog_comment_reject', methods: ['POST'])]
    public function reject(BlogComment $comment): JsonResponse
    {
        $comment->setIsApproved(false);
        $this->em->flush();
        $this->stats->recalculate($comment->getBlogPost());

        return $this->json(['success' => true, 'comment' => $this->serialize($comment)]);
    }

    #[Route('/{id}', name: 'admin_blog_comment_delete', methods: ['DELETE'])]
    public function delete(BlogComment $comment): JsonResponse
    {
        $post = $comment->getBlogPost();
        $this->em->remove($comment);
        $this->em->flush();
        if ($post) $this->stats->recalculate($post);

        return $this->json(['success' => true]);
    }

    private function serialize(BlogComment $c): array
    {
        return [
            'id' => (string) $c->getId(),
            'authorName' => $c->getAuthorName(),
            'authorEmail' => $c->getAuthorEmail(),
            'content' => $c->getContent(),
            'rating' => $c->getRating(),
            'isApproved' => $c->isApproved(),
            'createdAt' => $c->getCreatedAt()?->format('c'),
            'blogPost' => $c->getBlogPost() ? [
                'id' => (string) $c->getBlogPost()->getId(),
                'title' => $c->getBlogPost()->getTitle(),
                'slug' => $c->getBlogPost()->getSlug(),
            ] : null,
        ];
    }
}
