<?php

namespace App\Catalog\Service;

use App\Catalog\Entity\Product;
use App\Catalog\Repository\ReviewRepository;
use Doctrine\ORM\EntityManagerInterface;

class ReviewRatingService
{
    public function __construct(
        private ReviewRepository $reviewRepository,
        private EntityManagerInterface $em,
    ) {}

    /**
     * Recalculates product.rating and product.reviewsCount
     * based only on verified (isVerified=true) reviews.
     */
    public function recalculate(Product $product): void
    {
        $stats = $this->reviewRepository->getVerifiedStats($product);

        $product->setReviewsCount($stats['count']);
        $product->setRating($stats['avg'] ?? 0.0);

        $this->em->flush();
    }
}
