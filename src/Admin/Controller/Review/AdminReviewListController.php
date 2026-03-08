<?php

namespace App\Admin\Controller\Review;

use App\Catalog\Repository\ReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
#[Route('/api/admin/reviews', name: 'admin_reviews_list_')]
class AdminReviewListController extends AbstractController
{
    public function __construct(
        private ReviewRepository $reviewRepository,
        private EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $itemsPerPage = max(1, min(100, (int) $request->query->get('itemsPerPage', 20)));
        $isVerified = $request->query->has('isVerified') ? filter_var($request->query->get('isVerified'), FILTER_VALIDATE_BOOLEAN) : null;
        $nameFilter = $request->query->get('name');
        $sortOrder = $request->query->get('order[createdAt]', 'desc');

        $qb = $this->reviewRepository->createQueryBuilder('r')
            ->join('r.product', 'p')
            ->orderBy('r.createdAt', strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC');

        if ($isVerified !== null) {
            $qb->andWhere('r.isVerified = :isVerified')
               ->setParameter('isVerified', $isVerified);
        }

        if ($nameFilter) {
            $qb->andWhere('r.name LIKE :name')
               ->setParameter('name', '%' . $nameFilter . '%');
        }

        $totalQb = clone $qb;
        $total = (int) $totalQb->select('COUNT(r.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();

        $reviews = $qb->select('r')
            ->setFirstResult(($page - 1) * $itemsPerPage)
            ->setMaxResults($itemsPerPage)
            ->getQuery()
            ->getResult();

        $members = [];
        foreach ($reviews as $review) {
            $members[] = [
                '@id' => '/api/admin/reviews/' . $review->getId()->toRfc4122(),
                'id' => $review->getId()->toRfc4122(),
                'name' => $review->getName(),
                'email' => $review->getEmail(),
                'rating' => $review->getRating(),
                'comment' => $review->getComment(),
                'isVerified' => $review->getIsVerified(),
                'isPurchaseVerified' => $review->getIsPurchaseVerified(),
                'adminReply' => $review->getAdminReply(),
                'adminRepliedAt' => $review->getAdminRepliedAt()?->format(\DateTime::ATOM),
                'createdAt' => $review->getCreatedAt()?->format(\DateTime::ATOM),
                'productSlug' => $review->getProduct()?->getSlug(),
                'productName' => $review->getProduct()?->getName(),
            ];
        }

        return $this->json([
            '@context' => '/api/contexts/Review',
            '@id' => '/api/admin/reviews',
            '@type' => 'hydra:Collection',
            'hydra:member' => $members,
            'hydra:totalItems' => $total,
            'hydra:view' => [
                '@id' => '/api/admin/reviews?page=' . $page,
                '@type' => 'hydra:PartialCollectionView',
            ],
        ]);
    }
}
