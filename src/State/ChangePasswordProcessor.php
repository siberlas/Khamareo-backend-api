<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Doctrine\ORM\EntityManagerInterface;

class ChangePasswordProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private UserPasswordHasherInterface $passwordHasher,
        private EntityManagerInterface $em
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = [])
    {
        /** @var \App\Entity\User $user */
        $user = $this->security->getUser();

        // Vérifier mot de passe actuel
        if (!$this->passwordHasher->isPasswordValid($user, $data->currentPassword)) {
            throw new BadRequestHttpException("Votre mot de passe actuel est incorrect.");
        }

        // Mettre à jour le mot de passe
        $user->setPassword(
            $this->passwordHasher->hashPassword($user, $data->newPassword)
        );

        $this->em->flush();

        return [
            'message' => 'Mot de passe mis à jour avec succès ✅'
        ];
    }
}
