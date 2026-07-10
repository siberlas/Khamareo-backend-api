<?php

namespace App\Cart\Controller;

use App\Cart\Entity\Cart;
use App\Shared\Service\ClientContextResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Bundle\SecurityBundle\Security;
use App\Cart\Repository\CartRepository;

#[AsController]
class GetCurrentCartController extends AbstractController
{
     public function __construct(
        private Security $security,
        private CartRepository $cartRepository,
        private EntityManagerInterface $em,
        private ClientContextResolver $clientContext,
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
                $this->applyClientContext($cart, $request);
                $this->em->persist($cart);
                $this->em->flush();
            } elseif ($cart->getOsName() === null) {
                // Panier existant créé avant l'ajout de ce suivi : on complète a minima
                $this->applyClientContext($cart, $request);
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
                $updated = false;

                $country = $request->query->get('country');
                $resolvedCountry = $this->clientContext->resolveCountry($request) ?? $country;
                if ($cart->getGuestCountry() === null && $resolvedCountry && preg_match('/^[A-Z]{2}$/', $resolvedCountry)) {
                    $cart->setGuestCountry($resolvedCountry);
                    $updated = true;
                }

                $referrer = $request->query->get('referrer');
                if ($cart->getGuestReferrer() === null && $referrer) {
                    $cart->setGuestReferrer(substr($referrer, 0, 500));
                    $updated = true;
                }

                if ($cart->getOsName() === null) {
                    $this->applyClientContext($cart, $request, skipCountryReferrer: true);
                    $updated = true;
                }

                if ($updated) {
                    $this->em->flush();
                }

                return $cart;
            }
        }


         // 🔹 3️⃣ Si invité sans token → créer un nouveau panier invité
        $newCart = new Cart();
        $newCart->setIsActive(true);
        $newCart->setGuestToken(bin2hex(random_bytes(16)));

        $country = $request->query->get('country');
        $resolvedCountry = $this->clientContext->resolveCountry($request) ?? $country;
        if ($resolvedCountry && preg_match('/^[A-Z]{2}$/', $resolvedCountry)) {
            $newCart->setGuestCountry($resolvedCountry);
        }

        $referrer = $request->query->get('referrer');
        if ($referrer) {
            $newCart->setGuestReferrer(substr($referrer, 0, 500));
        }

        $newCart->setOsName($this->clientContext->resolveOsName($request));
        $newCart->setDeviceType($this->clientContext->resolveDeviceType($request));

        $this->em->persist($newCart);
        $this->em->flush();

        return $newCart;
    }

    private function applyClientContext(Cart $cart, Request $request, bool $skipCountryReferrer = false): void
    {
        $cart->setOsName($this->clientContext->resolveOsName($request));
        $cart->setDeviceType($this->clientContext->resolveDeviceType($request));

        if ($skipCountryReferrer) {
            return;
        }

        if ($cart->getGuestCountry() === null) {
            $country = $this->clientContext->resolveCountry($request) ?? $request->query->get('country');
            if ($country && preg_match('/^[A-Z]{2}$/', $country)) {
                $cart->setGuestCountry($country);
            }
        }

        if ($cart->getGuestReferrer() === null) {
            $referrer = $request->query->get('referrer');
            if ($referrer) {
                $cart->setGuestReferrer(substr($referrer, 0, 500));
            }
        }
    }
}
