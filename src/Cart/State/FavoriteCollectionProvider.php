<?php

namespace App\Cart\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Cart\Repository\FavoriteRepository;
use Symfony\Bundle\SecurityBundle\Security;

final class FavoriteCollectionProvider implements ProviderInterface
{
    public function __construct(
        private FavoriteRepository $repository,
        private Security $security
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        // Retourner uniquement les favoris de l'utilisateur connecté
        return $this->repository->findBy(
            ['owner' => $user],
            ['createdAt' => 'DESC']
        );
    }
}