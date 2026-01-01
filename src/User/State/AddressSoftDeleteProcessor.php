<?php 

namespace App\User\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AddressSoftDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = [])
    {
        // Vérification explicite avant la suppression
        $currentUser = $this->security->getUser();
        
        if ($data->getOwner() !== $currentUser) {
            throw new AccessDeniedHttpException("Vous n'avez pas le droit de supprimer cette adresse.");
        }

        $data->softDelete();
        $this->em->flush();

        return $data;
    }
}