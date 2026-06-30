<?php

namespace App\Cart\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Cart\Entity\CartItem;
use App\Marketing\Service\PromoCodeApplicationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class CartItemUpdateProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private PromoCodeApplicationService $promoService,
        private EntityManagerInterface $em,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);

        if ($data instanceof CartItem) {
            $cart = $data->getCart();
            if ($cart && !empty($cart->getPromoCodesData())) {
                $changed = $this->promoService->recalculatePercentageDiscounts($cart);
                if ($changed) {
                    $this->em->flush();
                }
            }
        }

        return $result;
    }
}
