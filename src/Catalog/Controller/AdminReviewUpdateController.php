<?php

namespace App\Catalog\Controller;

use App\Catalog\Entity\Review;
use App\Catalog\Repository\ReviewRepository;
use App\Catalog\Service\ReviewRatingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

#[AsController]
class AdminReviewUpdateController extends AbstractController
{
    public function __construct(
        private ReviewRepository $reviewRepository,
        private EntityManagerInterface $em,
        private ReviewRatingService $ratingService,
    ) {}

    public function __invoke(Request $request): Review
    {
        // Extract UUID from route attribute
        $idString = $request->attributes->get('id');
        if (!$idString) {
            throw new BadRequestHttpException('Missing review id');
        }

        try {
            $uuid = Uuid::fromString($idString);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid UUID');
        }

        $review = $this->reviewRepository->findOneBy(['id' => $uuid]);
        if (!$review) {
            throw new NotFoundHttpException('Avis introuvable');
        }

        $body = json_decode($request->getContent(), true) ?? [];

        $wasVerified = $review->getIsVerified();

        if (array_key_exists('isVerified', $body)) {
            $review->setIsVerified((bool) $body['isVerified']);
        }

        if (array_key_exists('adminReply', $body)) {
            $reply = trim((string) $body['adminReply']);
            $review->setAdminReply($reply !== '' ? $reply : null);
            $review->setAdminRepliedAt($reply !== '' ? new \DateTimeImmutable() : null);
        }

        $this->em->flush();

        // Recalculate product rating if verification status changed
        if ($wasVerified !== $review->getIsVerified()) {
            $this->ratingService->recalculate($review->getProduct());
        }

        return $review;
    }
}
