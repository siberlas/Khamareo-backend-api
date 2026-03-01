<?php

namespace App\Catalog\Controller;

use App\Catalog\Dto\ReviewInput;
use App\Catalog\Entity\Review;
use App\Catalog\Repository\ProductRepository;
use App\Catalog\Service\ReviewRatingService;
use App\Order\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\User\Entity\User;

#[AsController]
class ReviewCreateController extends AbstractController
{
    public function __construct(
        private ProductRepository $productRepository,
        private EntityManagerInterface $em,
        private Security $security,
        private ValidatorInterface $validator,
        private OrderRepository $orderRepository,
        private ReviewRatingService $ratingService,
    ) {}

    public function __invoke(ReviewInput $data): Review
    {
        $user = $this->security->getUser();
        $groups = $user ? ['Default'] : ['Default', 'guest'];

        $errors = $this->validator->validate($data, null, $groups);
        if (count($errors) > 0) {
            throw new BadRequestHttpException((string) $errors);
        }

        $productSlug = $this->extractProductSlug($data->product);
        $product = $this->productRepository->findOneBy(['slug' => $productSlug]);
        if (!$product) {
            throw new NotFoundHttpException('Produit introuvable');
        }

        $review = new Review();
        $review->setProduct($product);
        $review->setRating((int) $data->rating);
        $review->setComment((string) $data->comment);

        if ($user instanceof User) {
            $fullName = trim((string) $user->getFullName());
            $firstName = trim((string) $user->getFirstName());
            $lastName = trim((string) $user->getLastName());
            $displayName = $fullName !== '' ? $fullName : trim($firstName . ' ' . $lastName);

            if ($displayName === '') {
                $displayName = 'Utilisateur';
            }

            $review->setName($displayName);
            $review->setEmail($user->getEmail());
        } else {
            $review->setName((string) $data->name);
            $review->setEmail($data->email);
        }

        // Auto-verify if the reviewer has a paid order containing this product
        $email = $review->getEmail();
        if ($email && $this->orderRepository->hasVerifiedPurchase($email, $product)) {
            $review->setIsVerified(true);
            $review->setIsPurchaseVerified(true);
        }

        $this->em->persist($review);
        $this->em->flush();

        // Recalculate product rating (only verified reviews count)
        if ($review->getIsVerified()) {
            $this->ratingService->recalculate($product);
        }

        return $review;
    }

    private function extractProductSlug(?string $value): string
    {
        if (!$value) {
            throw new BadRequestHttpException('Produit requis');
        }

        $value = trim($value);

        if (str_starts_with($value, '/api/products/')) {
            return substr($value, strlen('/api/products/'));
        }
        if (str_starts_with($value, '/products/')) {
            return substr($value, strlen('/products/'));
        }

        return $value;
    }
}
