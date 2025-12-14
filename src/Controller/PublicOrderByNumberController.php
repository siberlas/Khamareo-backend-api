<?php

namespace App\Controller;

use App\Entity\Order;
use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Bundle\SecurityBundle\Security;

#[AsController]
class PublicOrderByNumberController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly Security $security,
    ) {}

    /**
     * Endpoint PUBLIC pour récupérer une commande par orderNumber.
     *
     * Règles :
     * - Si la commande a un owner (user connecté) :
     *      → l’utilisateur doit être connecté et être owner OU admin.
     * - Si la commande est une commande invité (owner === null) :
     *      → il faut fournir ?email=... qui doit matcher guestEmail.
     */
    public function __invoke(string $orderNumber, Request $request): JsonResponse
    {
        /** @var Order|null $order */
        $order = $this->orderRepository->findOneBy(['orderNumber' => $orderNumber]);

        if (!$order) {
            throw new NotFoundHttpException('Commande introuvable.');
        }

        // 1️⃣ Cas user connecté : commande avec owner
        if (null !== $order->getOwner()) {
            $user = $this->security->getUser();

            if (!$user) {
                throw new AccessDeniedHttpException('Authentification requise.');
            }

            if (
                !$this->security->isGranted('ROLE_ADMIN') &&
                $order->getOwner() !== $user
            ) {
                throw new AccessDeniedHttpException('Accès refusé à cette commande.');
            }

            // OK → on renvoie la commande sérialisée
            return $this->json(
                $order,
                200,
                [],
                ['groups' => ['order:read']]
            );
        }

        // 2️⃣ Cas commande invité (owner === null)
        $emailParam = $request->query->get('email');
        $guestEmail = $order->getGuestEmail();

        if (!$guestEmail) {
            // Configuration incohérente : commande sans owner ni guestEmail
            throw new AccessDeniedHttpException('Accès impossible à cette commande.');
        }

        if (!$emailParam) {
            throw new AccessDeniedHttpException('Email requis pour accéder à cette commande.');
        }

        if (\mb_strtolower(\trim($emailParam)) !== \mb_strtolower(\trim($guestEmail))) {
            throw new AccessDeniedHttpException('Email ne correspondant pas à la commande.');
        }

        // OK → invité légitime
        return $this->json(
            $order,
            200,
            [],
            ['groups' => ['order:read']]
        );
    }
}
