<?php

namespace App\Admin\Controller\Product;

use App\Catalog\Entity\Product;
use App\Catalog\Enum\ProductType;
use App\Catalog\Repository\ProductRepository;
use App\Catalog\Service\DigitalFileUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Remplacement / suppression du PDF source d'un livre numérique, sans repasser
 * par le formulaire produit complet. (L'upload initial peut aussi se faire
 * directement dans le formulaire de création/édition.)
 */
#[AsController]
#[Route('/api/admin/products', name: 'admin_products_digital_file_')]
class ProductDigitalFileController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductRepository $productRepository,
        private readonly DigitalFileUploader $uploader,
    ) {}

    #[Route('/{id}/digital-file', name: 'upload', methods: ['POST'])]
    public function upload(string $id, Request $request): JsonResponse
    {
        $product = $this->productRepository->find($id);
        if (!$product instanceof Product) {
            return $this->json(['success' => false, 'error' => 'Produit introuvable'], 404);
        }
        if ($product->getProductType() !== ProductType::DIGITAL) {
            return $this->json(['success' => false, 'error' => 'Ce produit n\'est pas de type numérique'], 400);
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->json(['success' => false, 'error' => 'Aucun fichier reçu (champ « file »)'], 400);
        }

        if ($error = $this->uploader->attach($product, $file)) {
            return $this->json(['success' => false, 'error' => $error], 400);
        }
        $this->em->flush();

        return $this->json([
            'success' => true,
            'digitalFileOriginalName' => $product->getDigitalFileOriginalName(),
            'digitalFileSizeBytes' => $product->getDigitalFileSizeBytes(),
            'hasDigitalFile' => true,
        ]);
    }

    #[Route('/{id}/digital-file', name: 'delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $product = $this->productRepository->find($id);
        if (!$product instanceof Product) {
            return $this->json(['success' => false, 'error' => 'Produit introuvable'], 404);
        }

        $this->uploader->detach($product);
        if ($product->getProductType() === ProductType::DIGITAL) {
            $product->setIsEnabled(false);
        }
        $this->em->flush();

        return $this->json(['success' => true, 'hasDigitalFile' => false, 'isEnabled' => $product->getIsEnabled()]);
    }
}
