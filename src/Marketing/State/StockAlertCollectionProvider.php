<?php

namespace App\Marketing\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Marketing\Repository\StockAlertRepository;
use Symfony\Bundle\SecurityBundle\Security;

final class StockAlertCollectionProvider implements ProviderInterface
{
    public function __construct(
        private StockAlertRepository $repository,
        private Security $security
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        // Retourner uniquement les alertes de l'utilisateur connecté
        return $this->repository->findBy(
            ['owner' => $user],
            ['createdAt' => 'DESC']
        );
    }
}