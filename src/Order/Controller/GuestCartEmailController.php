<?php

namespace App\Order\Controller;

use App\Cart\Entity\Cart;
use App\Shared\Exception\AccountExistsException;
use App\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Checkout invité 100 % numérique : seul l'email est requis (+ acceptation CGV).
 * Aucune adresse — le livre est livré par email. Crée / met à jour le owner
 * invité du panier avec l'email fourni.
 */
#[AsController]
class GuestCartEmailController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    #[Route('/api/guest/cart/checkout-email', name: 'guest_cart_checkout_email', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $guestToken = $request->query->get('guestToken');
        if (!$guestToken) {
            throw new BadRequestException('Le token invité est requis.');
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $hasAcceptedTerms = (bool) ($data['hasAcceptedTerms'] ?? false);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BadRequestException("Une adresse email valide est obligatoire.");
        }
        if (!$hasAcceptedTerms) {
            throw new BadRequestException("Vous devez accepter les conditions générales de vente.");
        }

        $cart = $this->em->getRepository(Cart::class)->findOneBy(['guestToken' => $guestToken, 'isActive' => true]);
        if (!$cart) {
            throw new BadRequestException("Aucun panier actif pour ce token.");
        }
        if (!$cart->hasOnlyDigitalItems()) {
            throw new BadRequestException("Ce panier contient des articles physiques : une adresse est requise.");
        }

        $user = $cart->getOwner();

        if ($user === null) {
            $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($existing) {
                if (!$existing->isGuest()) {
                    throw new AccountExistsException($email);
                }
                $user = $existing;
                $user->setHasAcceptedGuestTerms(true);
            } else {
                $user = new User();
                $user
                    ->setEmail($email)
                    ->setIsGuest(true)
                    ->setIsVerified(false)
                    ->setHasAcceptedGuestTerms(true);
                $user->setPassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(16))));
                $this->em->persist($user);
            }
            $cart->setOwner($user);
        } else {
            if ($user->isGuest() && strtolower((string) $user->getEmail()) !== $email) {
                $user->setEmail($email);
            }
            $user->setHasAcceptedGuestTerms(true);
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'email' => $user->getEmail(),
        ]);
    }
}
