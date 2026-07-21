<?php

namespace App\Cart\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Cart\Entity\CartItem;
use App\Marketing\Service\PromoCodeApplicationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class CartItemDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private ProcessorInterface $removeProcessor,
        private PromoCodeApplicationService $promoService,
        private EntityManagerInterface $em,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $cart = ($data instanceof CartItem) ? $data->getCart() : null;

        $result = $this->removeProcessor->process($data, $operation, $uriVariables, $context);

        if ($cart) {
            $cart->touch();

            if (!empty($cart->getPromoCodesData())) {
                $this->promoService->recalculatePercentageDiscounts($cart);
            }

            $this->em->flush();
        }

        return $result;
    }
}
