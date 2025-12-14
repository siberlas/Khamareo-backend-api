<?php

namespace App\Controller;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsController]
class UserController
{
    #[Route('/api/users/{id}', name: 'update_user', methods: ['PATCH'])]
    public function updateUser(
        int $id,
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $em,
        SerializerInterface $serializer,
        ValidatorInterface $validator
    ): JsonResponse {

        $user = $userRepository->find($id);

        if (!$user) {
            return new JsonResponse(['error' => 'Utilisateur non trouvé'], 404);
        }

        // On applique le PATCH (merge sur l’entité existante)
        $serializer->deserialize(
            $request->getContent(),
            get_class($user),
            'json',
            ['object_to_populate' => $user]
        );

        // Validation
        $errors = $validator->validate($user);
        if (count($errors) > 0) {
            return new JsonResponse((string) $errors, 400);
        }

        $em->persist($user);
        $em->flush();

        return new JsonResponse(['message' => 'Profil mis à jour ✅']);
    }
}
