<?php

namespace App\User\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use App\User\Entity\Address;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class AddressSetDefaultProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $em
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        /** @var Address $data */
        $user = $this->security->getUser();

        if (!$user) {
            throw new AccessDeniedHttpException("Vous devez être connecté.");
        }

        if (!$this->security->isGranted('ROLE_ADMIN') && $data->getOwner() !== $user) {
            throw new AccessDeniedHttpException("Vous ne pouvez modifier que vos propres adresses.");
        }

        if ($data->getAddressKind() === 'relay') {
            throw new BadRequestHttpException("Une adresse de point relais ne peut pas être définie comme adresse par défaut.");
        }

        $targetKind = $data->getAddressKind();
        $ownerId = $data->getOwner()->getId();

        // 1. Désactiver toutes les autres adresses du même type
        $this->em->createQueryBuilder()
            ->update(Address::class, 'a')
            ->set('a.isDefault', ':false')
            ->where('a.owner = :owner')
            ->andWhere('a.addressKind = :kind')
            ->andWhere('a.id != :currentId')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('false', false)
            ->setParameter('owner', $ownerId)
            ->setParameter('kind', $targetKind)
            ->setParameter('currentId', $data->getId())
            ->getQuery()
            ->execute();

        // 2. Activer cette adresse comme par défaut
        $data->setIsDefault(true);
        
        $this->em->flush();
        
        return $data;
    }
}