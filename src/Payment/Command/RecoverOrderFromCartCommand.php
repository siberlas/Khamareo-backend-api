<?php

namespace App\Payment\Command;

use App\Cart\Entity\Cart;
use App\Marketing\Entity\PromoCodeRedemption;
use App\Marketing\Repository\PromoCodeRepository;
use App\Marketing\Service\PromoCodeApplicationService;
use App\Order\Entity\Order;
use App\Order\Entity\OrderItem;
use App\Payment\Entity\Payment;
use App\Payment\Provider\StripePaymentProvider;
use App\Shared\Enum\OrderStatus;
use App\Shared\Enum\PaymentStatus;
use App\Shipping\Repository\CarrierModeRepository;
use App\User\Entity\Address;
use App\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:recover-order-from-cart',
    description: 'Crée une commande manuelle depuis un panier dont le paiement Stripe a réussi mais la commande na pas été créée.',
)]
class RecoverOrderFromCartCommand extends Command
{
    public function __construct(
        private EntityManagerInterface       $em,
        private StripePaymentProvider        $stripeProvider,
        private CarrierModeRepository        $carrierModeRepository,
        private PromoCodeRepository          $promoCodeRepository,
        private PromoCodeApplicationService  $promoCodeApplicationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('cart_id',          InputArgument::REQUIRED, 'UUID du panier')
            ->addArgument('user_id',          InputArgument::REQUIRED, 'UUID du user')
            ->addArgument('billing_address_id',  InputArgument::REQUIRED, 'ID adresse facturation (depuis Stripe metadata)')
            ->addArgument('delivery_address_id', InputArgument::REQUIRED, 'ID adresse livraison (depuis Stripe metadata)')
            ->addArgument('carrier_mode_id',     InputArgument::REQUIRED, 'ID carrier mode (depuis Stripe metadata)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $cartId            = $input->getArgument('cart_id');
        $userId            = $input->getArgument('user_id');
        $billingAddressId  = (int) $input->getArgument('billing_address_id');
        $deliveryAddressId = (int) $input->getArgument('delivery_address_id');
        $carrierModeId     = (int) $input->getArgument('carrier_mode_id');

        // ── 1) Charger le panier ──────────────────────────────────────────────
        $cart = $this->em->getRepository(Cart::class)->find($cartId);
        if (!$cart) {
            $io->error("Panier introuvable : $cartId");
            return Command::FAILURE;
        }

        if (!$cart->getPaymentIntentId()) {
            $io->error("Le panier n'a pas de payment_intent_id.");
            return Command::FAILURE;
        }

        $io->info("Panier trouvé : {$cart->getId()} — PaymentIntent : {$cart->getPaymentIntentId()}");

        // ── 2) Charger le user ────────────────────────────────────────────────
        $user = $this->em->getRepository(User::class)->find($userId);
        if (!$user) {
            $io->error("User introuvable : $userId");
            return Command::FAILURE;
        }

        $io->info("User : {$user->getEmail()}");

        // ── 3) Vérifier Stripe ────────────────────────────────────────────────
        $pi = $this->stripeProvider->retrievePaymentIntent($cart->getPaymentIntentId());
        $io->info("Stripe PI status : {$pi->status} — montant : " . ($pi->amount / 100) . " {$pi->currency}");

        if ($pi->status !== 'succeeded') {
            $io->error("Le PaymentIntent n'est pas 'succeeded' (status: {$pi->status}). Abandon.");
            return Command::FAILURE;
        }

        $piAmount = $pi->amount / 100;

        // ── 4) Adresses ───────────────────────────────────────────────────────
        $billingSource  = $this->em->getRepository(Address::class)->find($billingAddressId);
        $deliverySource = $this->em->getRepository(Address::class)->find($deliveryAddressId);

        if (!$billingSource || !$deliverySource) {
            $io->error("Adresse(s) introuvable(s). billing=$billingAddressId delivery=$deliveryAddressId");
            return Command::FAILURE;
        }

        // Snapshots figés
        $billingSnapshot = (new Address())
            ->setAddressKind($billingSource->getAddressKind())
            ->setStreetAddress($billingSource->getStreetAddress())
            ->setAddressComplement($billingSource->getAddressComplement())
            ->setCity($billingSource->getCity())
            ->setPostalCode($billingSource->getPostalCode())
            ->setCountry($billingSource->getCountry())
            ->setState($billingSource->getState())
            ->setLabel('Billing snapshot (recovered)')
            ->setIsDefault(false)->setOwner(null)
            ->setLatitude($billingSource->getLatitude())
            ->setLongitude($billingSource->getLongitude())
            ->setCivility($billingSource->getCivility())
            ->setFirstName($billingSource->getFirstName())
            ->setLastName($billingSource->getLastName())
            ->setPhone($billingSource->getPhone())
            ->setIsBusiness($billingSource->isBusiness())
            ->setCompanyName($billingSource->getCompanyName());
        $this->em->persist($billingSnapshot);

        $shippingSnapshot = (new Address())
            ->setAddressKind($deliverySource->getAddressKind())
            ->setStreetAddress($deliverySource->getStreetAddress())
            ->setAddressComplement($deliverySource->getAddressComplement())
            ->setCity($deliverySource->getCity())
            ->setPostalCode($deliverySource->getPostalCode())
            ->setCountry($deliverySource->getCountry())
            ->setState($deliverySource->getState())
            ->setLabel('Shipping snapshot (recovered)')
            ->setIsDefault(false)->setOwner(null)
            ->setLatitude($deliverySource->getLatitude())
            ->setLongitude($deliverySource->getLongitude())
            ->setCivility($deliverySource->getCivility())
            ->setFirstName($deliverySource->getFirstName())
            ->setLastName($deliverySource->getLastName())
            ->setPhone($deliverySource->getPhone())
            ->setIsBusiness($deliverySource->isBusiness())
            ->setCompanyName($deliverySource->getCompanyName());

        if ($deliverySource->isRelayPoint()) {
            $shippingSnapshot
                ->setAddressKind('relay')
                ->setIsRelayPoint(true)
                ->setRelayPointId($deliverySource->getRelayPointId())
                ->setRelayCarrier($deliverySource->getRelayCarrier());
        }
        $this->em->persist($shippingSnapshot);

        // ── 5) CarrierMode ────────────────────────────────────────────────────
        $carrierMode = $this->carrierModeRepository->find($carrierModeId);
        if (!$carrierMode) {
            $io->error("CarrierMode introuvable : $carrierModeId");
            return Command::FAILURE;
        }

        $io->info("Carrier : {$carrierMode->getCarrier()?->getName()} — {$carrierMode->getShippingMode()?->getName()}");

        // ── 6) Créer la commande ──────────────────────────────────────────────
        $shippingCost = $cart->getShippingCost() ?? 0;
        $discountAmount = $cart->getDiscountAmount() ? (float) $cart->getDiscountAmount() : 0;

        $order = new Order();
        $order
            ->setStatus(OrderStatus::PAID)
            ->setPaymentStatus('paid')
            ->setOwner($user)
            ->setCarrier($carrierMode->getCarrier())
            ->setShippingMode($carrierMode->getShippingMode())
            ->setCarrierMode($carrierMode)
            ->setBillingAddress($billingSnapshot)
            ->setShippingAddress($shippingSnapshot)
            ->setShippingCost($shippingCost)
            ->setCarrierShippingCost($cart->getCarrierShippingCost() ?? $shippingCost)
            ->setTotalAmount($piAmount)
            ->setCurrency('EUR')
            ->setLocale('fr');

        if ($cart->getPromoCode()) {
            $order->setPromoCode($cart->getPromoCode())
                ->setDiscountAmount($cart->getDiscountAmount())
                ->setPromoCodesData($cart->getPromoCodesData());
        }

        // ── 7) OrderItems ─────────────────────────────────────────────────────
        foreach ($cart->getItems() as $cartItem) {
            $item = (new OrderItem())
                ->setCustomerOrder($order)
                ->setProduct($cartItem->getProduct())
                ->setQuantity($cartItem->getQuantity())
                ->setUnitPrice($cartItem->getUnitPrice());
            $this->em->persist($item);
            $io->text("  → {$cartItem->getProduct()?->getName()} x{$cartItem->getQuantity()} @ {$cartItem->getUnitPrice()}€");
        }

        // ── 8) Payment ────────────────────────────────────────────────────────
        $payment = (new Payment())
            ->setOrder($order)
            ->setProvider('stripe')
            ->setStatus(PaymentStatus::PAID)
            ->setProviderPaymentId($pi->id)
            ->setClientSecret($cart->getPaymentClientSecret())
            ->setAmount($piAmount);
        $this->em->persist($payment);

        // ── 9) Rédemptions promo ──────────────────────────────────────────────
        $codesData = $cart->getPromoCodesData() ?? [];
        if (empty($codesData) && $cart->getPromoCode()) {
            $codesData = [['code' => $cart->getPromoCode(), 'discount' => $discountAmount, 'stackable' => false]];
        }

        foreach ($codesData as $codeData) {
            $promoCode = $this->promoCodeRepository->findOneBy(['code' => $codeData['code']]);
            if (!$promoCode) continue;

            $redemption = (new PromoCodeRedemption())
                ->setPromoCode($promoCode)
                ->setEmail($user->getEmail())
                ->setCustomerType('registered')
                ->setOrder($order)
                ->setDiscountAmount((string) ($codeData['discount'] ?? 0));
            $this->em->persist($redemption);

            if ($promoCode->isSingleInstance()) {
                $this->promoCodeApplicationService->markAsUsed($promoCode);
            }
        }

        // ── 10) Sauvegarder ───────────────────────────────────────────────────
        $this->em->persist($order);

        $this->em->flush();

        $io->success("Commande créée : {$order->getOrderNumber()} — status: PAID — total: {$piAmount}€");

        return Command::SUCCESS;
    }
}
