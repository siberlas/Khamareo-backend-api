<?php
// src/Controller/PaymentMethodDeleteController.php
namespace App\Controller;

use App\Entity\User;
use App\Payment\StripePaymentProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class PaymentMethodDeleteController extends AbstractController
{
    public function __construct(
        private readonly Security $security,
        private readonly StripePaymentProvider $stripe
    ) {}

    #[Route('/api/payment-methods/{id}', name: 'payment_methods_delete', methods: ['DELETE'])]
    public function __invoke(string $id): Response
    {
        /** @var User $user */
        $user = $this->security->getUser();
        // Optionnel : vérifier que le PM appartient bien au customer du user (via API Stripe)
        $this->stripe->detachPaymentMethod($id);
        return new Response('', 204);
    }
}
