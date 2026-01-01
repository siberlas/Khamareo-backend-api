<?php
// src/Controller/PaymentMethodSetupController.php
namespace App\Payment\Controller;

use App\User\Entity\User;
use App\Payment\StripePaymentProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class PaymentMethodSetupController extends AbstractController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly StripePaymentProvider $stripe
    ) {}

    #[Route('/api/payment-methods/setup', name: 'payment_methods_setup', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();
        $customerId = $this->stripe->ensureCustomerFor($user);
        // on_session si tu encaisses toujours en présence du client ; sinon off_session
        $resp = $this->stripe->createSetupIntent($customerId, usage: 'on_session');

        // si tu veux persister user->stripeCustomerId lors du premier setup :
        $this->em->flush();

        return $this->json(['clientSecret' => $resp->clientSecret]);
    }
}
