<?php
// src/Controller/TestColissimoController.php

namespace App\Dev\Controller;

use App\Order\Entity\Order;
use App\Order\Repository\OrderRepository;
use App\Shipping\Service\ColissimoApiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpFoundation\Response; // ✅ Ajoute cette ligne

#[AsController]
#[Route('/test-colissimo')]
#[IsGranted('PUBLIC_ACCESS')]
class TestColissimoController extends AbstractController
{
    public function __construct(
        private ColissimoApiService $colissimoService,
        private OrderRepository $orderRepository
    ) {}

    /**
     * Test avec une commande existante
     * GET /test-colissimo/generate/{orderNumber}
     */
    #[Route('/generate/{orderNumber}', methods: ['GET'])]
    public function testGenerate(string $orderNumber): JsonResponse
    {
        // Récupérer une commande réelle
        $order = $this->orderRepository->findOneBy(['orderNumber' => $orderNumber]);

        if (!$order) {
            return $this->json([
                'error' => 'Commande non trouvée',
            ], 404);
        }

        // Vérifier qu'elle a une adresse
        if (!$order->getShippingAddress()) {
            return $this->json([
                'error' => 'Commande sans adresse de livraison',
            ], 400);
        }

        try {
            // Appeler Colissimo
            $result = $this->colissimoService->generateLabel($order);

            return $this->json([
                'success' => true,
                'order' => $order->getOrderNumber(),
                'tracking' => $result['trackingNumber'],
                'provider' => $result['provider'],
                'labelPdfLength' => strlen($result['labelPdf']), // Longueur base64
                'canDownload' => true,
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }

    /**
     * Test avec téléchargement du PDF
     * GET /test-colissimo/download/{orderNumber}
     */
   #[Route('/download/{orderNumber}', name: 'download', methods: ['GET'])]
    public function testDownload(string $orderNumber): Response
    {
        $order = $this->orderRepository->findOneBy(['orderNumber' => $orderNumber]);
        
        if (!$order) {
            throw $this->createNotFoundException('Order not found');
        }

        try {
            // Génère l'étiquette
            $result = $this->colissimoService->generateLabel($order);
            
            // ✅ Vérifie que le PDF existe
            if (!isset($result['labelPdf']) || empty($result['labelPdf'])) {
                return new JsonResponse(['error' => 'PDF non disponible'], 404);
            }
            
            // ✅ Décode le base64
            $pdfContent = base64_decode($result['labelPdf']);
            
            // ✅ Retourne le PDF avec les bons headers
            $response = new Response($pdfContent);
            $response->headers->set('Content-Type', 'application/pdf');
            $response->headers->set('Content-Disposition', 'attachment; filename="etiquette_' . $orderNumber . '.pdf"');
            $response->headers->set('Content-Length', strlen($pdfContent));
            
            return $response;
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }
    /**
     * Lister les commandes disponibles pour test
     * GET /test-colissimo/orders
     */
    #[Route('/orders', methods: ['GET'])]
    public function listOrders(): JsonResponse
    {
        $orders = $this->orderRepository->createQueryBuilder('o')
            ->where('o.shippingAddress IS NOT NULL')
            ->andWhere('o.status = :status')
            ->setParameter('status', 'pending')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $data = array_map(function (Order $order) {
            $address = $order->getShippingAddress();
            return [
                'orderNumber' => $order->getOrderNumber(),
                'total' => $order->getTotalAmount(),
                'postalCode' => $address->getPostalCode(),
                'isOM' =>  in_array(substr(str_replace(' ', '', $address->getPostalCode()), 0, 3), ['971', '972', '973', '974', '976']),
                'testUrl' => '/test-colissimo/generate/' . $order->getOrderNumber(),
                'downloadUrl' => '/test-colissimo/download/' . $order->getOrderNumber(),
            ];
        }, $orders);

        return $this->json([
            'count' => count($data),
            'orders' => $data,
        ]);
    }
}