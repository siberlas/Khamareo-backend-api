<?php

namespace App\User\Controller;

use App\Cart\Entity\Cart;
use App\Cart\Service\GuestConversionService;
use App\User\Entity\User;
use App\User\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
class GuestConversionController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly GuestConversionService $conversionService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * POST /api/users/convert-guest
     * Convertit un utilisateur invité en compte utilisateur
     * Body:
     * {
     *   "email": "guest@example.com",
     *   "password": "newPassword",
     *   "guestToken": "...",
     *   "acceptTerms": true
     * }
     */
    #[Route('/api/users/convert-guest', name: 'convert_guest_user', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $email = (string) ($data['email'] ?? '');
        $password = (string) ($data['password'] ?? '');
        $guestToken = (string) ($data['guestToken'] ?? '');
        $acceptTerms = (bool) ($data['acceptTerms'] ?? false);

        if (!$email || !$password || !$guestToken) {
            throw new BadRequestHttpException('Email, password et guestToken sont requis.');
        }

        if (!$acceptTerms) {
            throw new BadRequestHttpException('Vous devez accepter les conditions générales.');
        }

        /** @var User|null $user */
        $user = $this->userRepository->findOneBy(['email' => $email]);
        if (!$user) {
            throw new NotFoundHttpException('Utilisateur invité introuvable.');
        }

        if (!$user->isGuest()) {
            throw new BadRequestHttpException('Ce compte est déjà un utilisateur enregistré.');
        }

        if ($user->isGuestExpired()) {
            throw new AccessDeniedHttpException('Invitation expirée.');
        }

        $cart = $this->em->getRepository(Cart::class)->findOneBy([
            'guestToken' => $guestToken,
            'isActive' => true,
        ]);

        if (!$cart) {
            throw new NotFoundHttpException('Panier invité introuvable pour ce token.');
        }

        if ($cart->getOwner() !== $user) {
            throw new AccessDeniedHttpException('Ce token ne correspond pas à cet utilisateur invité.');
        }

        $this->logger->info('🔄 Guest conversion requested', [
            'email' => $email,
            'user_id' => $user->getId(),
        ]);

        $user->setAcceptTerms(true);
        $this->conversionService->convertGuestToUser($user, $password);

        return $this->json([
            'success' => true,
            'message' => 'Conversion invitée réussie. Vérifiez votre email pour confirmer le compte.',
            'user' => '/api/users/' . $user->getId(),
        ]);
    }
}