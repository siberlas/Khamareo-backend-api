<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\Payment;
use App\Repository\CartRepository;
use App\Repository\ShippingMethodRepository;
use App\Repository\AddressRepository;
use App\Service\ShippingRateCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class OrderCheckoutController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private CartRepository $cartRepository,
        private ShippingMethodRepository $shippingMethodRepository,
        private AddressRepository $addressRepository,
        private ShippingRateCalculator $shippingRateCalculator, // ✅ ajouté
    ) {}

    #[Route('/api/cart/checkout', name: 'api_cart_checkout', methods: ['POST'])]
    public function checkout(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $cart = $this->cartRepository->findOneBy(['owner' => $this->getUser()]);
        if (!$cart) {
            return $this->json(['error' => 'Panier introuvable'], 404);
        }

        $shippingMethod = $this->shippingMethodRepository->find($data['shippingMethod']);
        $shippingAddress = $this->addressRepository->find($data['address']);

        if (!$shippingMethod || !$shippingAddress) {
            return $this->json(['error' => 'Méthode de livraison ou adresse non valide'], 400);
        }

        // ✅ Création de la commande
        $order = new Order();
        $order->setOwner($this->getUser());
        $order->setShippingMethod($shippingMethod);
        $order->setAddress($address);
        $order->setStatus('pending');
        $order->setPaymentStatus('unpaid');

        // ✅ Copier les items du panier vers la commande
        foreach ($cart->getItems() as $cartItem) {
            $order->addItem(clone $cartItem);
        }

        // ✅ Calcul du total du panier
        $subtotal = array_reduce($cart->getItems()->toArray(), function ($total, $item) {
            return $total + ($item->getProduct()->getPrice() * $item->getQuantity());
        }, 0);

        // ✅ Calcul du tarif de livraison
        $shippingCost = $this->shippingRateCalculator->calculate($order);
        $order->setShippingCost($shippingCost);

        // ✅ Montant total final
        $order->setTotalAmount($subtotal + $shippingCost);

        // ✅ Création du paiement associé
        $payment = (new Payment())
            ->setAmount($order->getTotalAmount())
            ->setStatus('pending')
            ->setProvider('manual')
            ->setOrder($order);

        $order->setPayment($payment);

        $this->em->persist($order);
        $this->em->persist($payment);
        $this->em->flush();

        return $this->json([
            'message' => 'Commande créée avec succès',
            'order' => [
                'id' => $order->getId(),
                'total' => $order->getTotalAmount(),
                'shippingCost' => $shippingCost,
                'status' => $order->getStatus(),
                'paymentStatus' => $order->getPaymentStatus(),
            ]
        ], 201);
    }
}
