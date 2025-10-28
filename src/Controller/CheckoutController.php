<?php

namespace App\Controller;

use App\Entity\Cart;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Payment;
use App\Enum\OrderStatus;
use App\Enum\PaymentStatus;
use App\Entity\ShippingMethod;
use App\Entity\ShippingAddress;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[AsController]
class CheckoutController extends AbstractController
{
    public function __invoke(Request $request, EntityManagerInterface $em, Security $security): Order
    {
        $user = $security->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException("Utilisateur non authentifié");
        }

        $cart = $em->getRepository(Cart::class)->findOneBy(['owner' => $user, 'isActive' => true]);
        if (!$cart || $cart->getItems()->isEmpty()) {
            throw new BadRequestException("Panier vide ou inexistant");
        }

       // Récupération du mode et de l’adresse de livraison
        $shippingMethod = null;
        $shippingAddress = null;


        $data = json_decode($request->getContent(), true);

        
        // Création de la commande
        $order = new Order();
        $order->setOwner($user)
              ->setStatus(OrderStatus::PENDING)
              ->setOrderNumber('ORD-' . date('dmY') . '-' . random_int(1000, 9999))
              ->setDeliveryAddress('Adresse à renseigner…');

        if (!empty($data['shippingMethod'])) {
            $shippingMethod = $em->getRepository(ShippingMethod::class)->findOneBy([
                'id' => basename($data['shippingMethod'])
            ]);
            if ($shippingMethod) {
                $order->setShippingMethod($shippingMethod);
            }
        }

        // ✅ Lier ShippingAddress
        if (!empty($data['shippingAddress'])) {
            $shippingAddress = $em->getRepository(ShippingAddress::class)->findOneBy([
                'id' => basename($data['shippingAddress'])
            ]);
            if ($shippingAddress) {
                $order->setShippingAddress($shippingAddress);
                $order->setDeliveryAddress($shippingAddress->getFullAddress());
            }
        }

        if (!$shippingMethod) {
            throw new BadRequestHttpException("Méthode de livraison invalide ou manquante.");
        }

        if (!$shippingAddress) {
            throw new BadRequestHttpException("Adresse de livraison invalide ou manquante.");
        }
        
        

        $total = 0;

        // Conversion des CartItem en OrderItem
        foreach ($cart->getItems() as $cartItem) {
            $orderItem = (new OrderItem())
                ->setCustomerOrder($order)
                ->setProduct($cartItem->getProduct())
                ->setQuantity($cartItem->getQuantity())
                ->setUnitPrice($cartItem->getUnitPrice());

            $em->persist($orderItem);
            $order->addItem($orderItem);

            $total += $cartItem->getQuantity() * $cartItem->getUnitPrice();
        }

        $order->setTotalAmount($total);

        // Création du paiement lié à la commande
        $payment = new Payment();
        $payment->setOrder($order)
                ->setAmount($total)
                ->setProvider('manual') // ou Stripe/PayPal plus tard
                ->setStatus(PaymentStatus::PENDING);
        $order->setPayment($payment);

        $em->persist($order);
        $em->persist($payment);

        // Désactivation + archivage du panier
        $cart->setIsActive(false);


        // Suppression sécurisée du panier
        foreach ($cart->getItems() as $item) {
            $em->remove($item);
        }
        $em->remove($cart);

        $em->flush();

        return $order;
    }
}
