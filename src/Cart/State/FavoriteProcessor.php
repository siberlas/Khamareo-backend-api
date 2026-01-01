<?php

namespace App\Cart\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Cart\Entity\Favorite;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Psr\Log\LoggerInterface;

final class FavoriteProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private LoggerInterface $logger
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Favorite
    {
        assert($data instanceof Favorite);

        $user = $this->security->getUser();
        
        if (!$user) {
            throw new BadRequestHttpException('Vous devez être connecté pour ajouter un favori.');
        }

        // Assigner l'utilisateur (owner)
        $data->setOwner($user);

        $this->em->persist($data);
        $this->em->flush();

        $this->logger->info('Favorite created', [
            'favorite_id' => $data->getId(),
            'owner_id' => $user->getId(),
            'product_id' => $data->getProduct()->getId(),
        ]);

        return $data;
    }
}