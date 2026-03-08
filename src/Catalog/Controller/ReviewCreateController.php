<?php

namespace App\Catalog\Controller;

use App\Catalog\Entity\Review;
use App\Catalog\Repository\ProductRepository;
use App\Catalog\Service\ReviewRatingService;
use App\Order\Repository\OrderRepository;
use App\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Security;

#[AsController]
#[Route('/api/reviews', name: 'review_create', methods: ['POST'])]
class ReviewCreateController extends AbstractController
{
    public function __construct(
        private ProductRepository $productRepository,
        private EntityManagerInterface $em,
        private Security $security,
        private OrderRepository $orderRepository,
        private ReviewRatingService $ratingService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return $this->json(['error' => 'Données JSON invalides'], 400);
        }

        $productSlug = $this->extractProductSlug($data['product'] ?? null);
        if (!$productSlug) {
            return $this->json(['error' => 'Produit requis'], 400);
        }

        $product = $this->productRepository->findOneBy(['slug' => $productSlug]);
        if (!$product) {
            return $this->json(['error' => 'Produit introuvable'], 404);
        }

        $rating = (int) ($data['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            return $this->json(['error' => 'La note doit être entre 1 et 5'], 400);
        }

        $comment = trim((string) ($data['comment'] ?? ''));
        if (strlen($comment) < 5) {
            return $this->json(['error' => 'Le commentaire doit faire au moins 5 caractères'], 400);
        }

        $user = $this->security->getUser();

        $review = new Review();
        $review->setProduct($product);
        $review->setRating($rating);
        $review->setComment($comment);

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
            $name = trim((string) ($data['name'] ?? ''));
            $email = trim((string) ($data['email'] ?? ''));
            if (strlen($name) < 2) {
                return $this->json(['error' => 'Le nom doit faire au moins 2 caractères'], 400);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->json(['error' => 'Email invalide'], 400);
            }
            $review->setName($name);
            $review->setEmail($email);
        }

        // Auto-verify if the reviewer has a paid order containing this product
        $email = $review->getEmail();
        if ($email && $this->orderRepository->hasVerifiedPurchase($email, $product)) {
            $review->setIsVerified(true);
            $review->setIsPurchaseVerified(true);
        }

        $this->em->persist($review);
        $this->em->flush();

        if ($review->getIsVerified()) {
            $this->ratingService->recalculate($product);
        }

        return $this->json([
            '@id' => '/api/reviews/' . $review->getId()->toRfc4122(),
            'id' => $review->getId()->toRfc4122(),
            'name' => $review->getName(),
            'rating' => $review->getRating(),
            'comment' => $review->getComment(),
            'isVerified' => $review->getIsVerified(),
            'createdAt' => $review->getCreatedAt()?->format(\DateTime::ATOM),
        ], 201);
    }

    private function extractProductSlug(?string $value): ?string
    {
        if (!$value) {
            return null;
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
