<?php

namespace App\User\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\User\Repository\AddressRepository;
use Symfony\Bundle\SecurityBundle\Security;

class AddressProvider implements ProviderInterface
{
    public function __construct(
        private AddressRepository $addressRepository,
        private Security $security
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $user = $this->security->getUser();
        
        if (!$user) {
            return null;
        }

        $isAdmin = $this->security->isGranted('ROLE_ADMIN');

        // Si c'est une collection (GetCollection)
        if (!isset($uriVariables['id'])) {
            // Admin voit tout
            if ($isAdmin) {
                return $this->addressRepository->findBy(['deletedAt' => null]);
            }
            
            // Utilisateur normal voit uniquement ses adresses
            return $this->addressRepository
            ->createQueryBuilder('a')
            ->where('a.owner = :user')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
            dd($results[0]);

        }

        // Si c'est un item individuel (Get, Patch, Delete)
        $address = $this->addressRepository->find($uriVariables['id']);
        
        if (!$address || $address->isDeleted()) {
            return null;
        }
        
        // Admin peut accéder à toutes les adresses
        if ($isAdmin) {
            return $address;
        }
        
        // Utilisateur normal ne peut accéder qu'à ses propres adresses
        if ($address->getOwner() === $user) {
            return $address;
        }
        
        return null;
    }
}