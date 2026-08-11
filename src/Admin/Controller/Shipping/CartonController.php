<?php

namespace App\Admin\Controller\Shipping;

use App\Shipping\Entity\Carton;
use App\Shipping\Repository\CartonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
#[Route('/api/admin', name: 'admin_cartons_')]
class CartonController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CartonRepository $cartonRepository,
    ) {}

    #[Route('/cartons', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json([
            'cartons' => array_map(
                fn (Carton $c) => $this->serialize($c),
                $this->cartonRepository->findAllOrdered()
            ),
        ]);
    }

    #[Route('/cartons', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $error = $this->validatePayload($data);
        if ($error) {
            return $this->json(['error' => $error], 400);
        }

        $carton = new Carton();
        $this->applyPayload($carton, $data);

        $this->em->persist($carton);
        $this->em->flush();

        return $this->json(['success' => true, 'carton' => $this->serialize($carton)], 201);
    }

    #[Route('/cartons/{id}', name: 'update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $carton = $this->cartonRepository->find($id);
        if (!$carton) {
            return $this->json(['error' => 'Carton introuvable'], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $error = $this->validatePayload($data);
        if ($error) {
            return $this->json(['error' => $error], 400);
        }

        $this->applyPayload($carton, $data);
        $this->em->flush();

        return $this->json(['success' => true, 'carton' => $this->serialize($carton)]);
    }

    #[Route('/cartons/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $carton = $this->cartonRepository->find($id);
        if (!$carton) {
            return $this->json(['error' => 'Carton introuvable'], 404);
        }

        $this->em->remove($carton);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    private function validatePayload(array $data): ?string
    {
        if (empty(trim((string) ($data['name'] ?? '')))) {
            return 'Le nom/référence est obligatoire.';
        }
        foreach (['lengthCm', 'widthCm', 'heightCm', 'emptyWeightGrams'] as $field) {
            if (!isset($data[$field]) || !is_numeric($data[$field]) || (int) $data[$field] <= 0) {
                return "Le champ {$field} doit être un nombre strictement positif.";
            }
        }
        return null;
    }

    private function applyPayload(Carton $carton, array $data): void
    {
        $carton
            ->setName(trim((string) $data['name']))
            ->setLengthCm((int) $data['lengthCm'])
            ->setWidthCm((int) $data['widthCm'])
            ->setHeightCm((int) $data['heightCm'])
            ->setEmptyWeightGrams((int) $data['emptyWeightGrams']);
    }

    private function serialize(Carton $carton): array
    {
        return [
            'id' => $carton->getId()->toRfc4122(),
            'name' => $carton->getName(),
            'lengthCm' => $carton->getLengthCm(),
            'widthCm' => $carton->getWidthCm(),
            'heightCm' => $carton->getHeightCm(),
            'emptyWeightGrams' => $carton->getEmptyWeightGrams(),
            'createdAt' => $carton->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
