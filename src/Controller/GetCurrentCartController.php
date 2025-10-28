<?php

namespace App\Controller;

use App\Entity\Cart;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Bundle\SecurityBundle\Security;
use App\Repository\CartRepository;

#[AsController]
class GetCurrentCartController extends AbstractController
{
     public function __construct(
        private Security $security,
        private CartRepository $cartRepository,
        private EntityManagerInterface $em
    ) {}

    public function __invoke(Request $request): ?Cart
    {
        $user = $this->security->getUser();
        $guestToken = $request->query->get('guestToken');

        if ($user) {
            $cart = $this->cartRepository->findOneBy([
                'owner' => $user,
                'isActive' => true,
            ]);

            if (!$cart) {
                $cart = new Cart();
                $cart->setOwner($user);
                $cart->setIsActive(true);
                $this->em->persist($cart);
                $this->em->flush();
            }

            return $cart;
        }

        // 🔹 2️⃣ Si invité avec un guestToken → retrouver son panier
        if ($guestToken) {
            $cart = $this->cartRepository->findOneBy([
                'guestToken' => $guestToken,
                'isActive' => true,
            ]);

            if ($cart) {
                return $cart;
            }
        }


         // 🔹 3️⃣ Si invité sans token → créer un nouveau panier invité
        $newCart = new Cart();
        $newCart->setIsActive(true);
        $newCart->setGuestToken(bin2hex(random_bytes(16)));

        $this->em->persist($newCart);
        $this->em->flush();

        return $newCart;
    }
}
