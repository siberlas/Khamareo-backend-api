<?php
// src/Controller/PaymentMethodListController.php
namespace App\Payment\Controller;

use App\User\Entity\User;
use App\Payment\Provider\StripePaymentProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class PaymentMethodListController extends AbstractController
{
    public function __construct(
        private readonly Security $security,
        private readonly StripePaymentProvider $stripe
    ) {}

    #[Route('/api/payment-methods', name: 'payment_methods_list', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();
        if (!$user->getStripeCustomerId()) {
            return $this->json([]);
        }
        return $this->json($this->stripe->listCards($user->getStripeCustomerId()));
    }
}
