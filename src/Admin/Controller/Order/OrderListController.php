<?php

namespace App\Admin\Controller\Order;

use App\Order\Repository\OrderRepository;
use App\Shipping\Repository\CarrierRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
#[Route('/api/admin/orders', name: 'admin_orders_list_')]
class OrderListController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private OrderRepository $orderRepository,
        private CarrierRepository $carrierRepository,
        private LoggerInterface $logger
    ) {}

   /**
     * Liste des transporteurs disponibles
     * 
     * GET /api/admin/orders/carriers
     * 
     * Response:
     * {
     *   "success": true,
     *   "carriers": [
     *     {"value": "colissimo", "label": "Colissimo"},
     *     {"value": "ups", "label": "UPS"},
     *     ...
     *   ]
     * }
     */
    #[Route('/carriers', name: 'carriers', methods: ['GET'])]
    public function getCarriers(): JsonResponse
    {
        try {
            // Récupérer tous les carriers actifs depuis la base de données
            $carriers = $this->carrierRepository->findBy(
                ['isActive' => true],
                ['name' => 'ASC']
            );

            $carriersData = array_map(function($carrier) {
                return [
                    'value' => $carrier->getCode(),
                    'label' => $carrier->getName(),
                ];
            }, $carriers);

            return $this->json([
                'success' => true,
                'carriers' => $carriersData,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Get carriers list failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la récupération des transporteurs'
            ], 500);
        }
    }

   /**
     * Statistiques des commandes
     * 
     * GET /api/admin/orders/stats?period=30days
     */
    #[Route('/stats', name: 'stats', methods: ['GET'])]
    public function getStats(Request $request): JsonResponse
    {
        try {
            // Période
            $period = $request->query->get('period', '30days');
            
            // Calculer les dates
            $endDate = new \DateTimeImmutable('now');
            $startDate = match($period) {
                '7days' => $endDate->modify('-7 days'),
                '30days' => $endDate->modify('-30 days'),
                '90days' => $endDate->modify('-90 days'),
                '1year' => $endDate->modify('-1 year'),
                'all' => null,
                default => $endDate->modify('-30 days'),
            };

            // Utiliser les méthodes du repository
            $totalOrders = $this->orderRepository->countOrdersByPeriod($startDate);
            $totalRevenue = $this->orderRepository->getTotalRevenue($startDate);
            $averageOrderValue = $this->orderRepository->getAverageOrderValue($startDate);
            $ordersByStatus = $this->orderRepository->countOrdersByStatus($startDate);
            $revenueByStatus = $this->orderRepository->getRevenueByStatus($startDate);

            return $this->json([
                'success' => true,
                'stats' => [
                    'totalOrders' => $totalOrders,
                    'totalRevenue' => $totalRevenue,
                    'averageOrderValue' => $averageOrderValue,
                    'ordersByStatus' => $ordersByStatus,
                    'revenueByStatus' => $revenueByStatus,
                    'period' => $period,
                    'periodStart' => $startDate?->format(\DateTime::ATOM),
                    'periodEnd' => $endDate->format(\DateTime::ATOM),
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Get orders stats failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la récupération des statistiques'
            ], 500);
        }
    }

    /**
     * Liste des commandes avec pagination et filtres
     * 
     * GET /api/admin/orders?page=1&itemsPerPage=20&status=pending&search=john&carrier=colissimo&startDate=2025-01-01&endDate=2025-01-31&orderBy=createdAt&orderDir=desc
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            // Pagination
            $page = max(1, $request->query->getInt('page', 1));
            $itemsPerPage = min(100, max(1, $request->query->getInt('itemsPerPage', 20)));
            $offset = ($page - 1) * $itemsPerPage;

            // Filtres
            $status = $request->query->get('status');
            $carrier = $request->query->get('carrier');
            $search = $request->query->get('search');
            $startDate = $request->query->get('startDate');
            $endDate = $request->query->get('endDate');

            // Tri
            $orderBy = $request->query->get('orderBy', 'createdAt');
            $orderDir = strtoupper($request->query->get('orderDir', 'DESC'));

            // Validation tri
            $allowedOrderBy = ['createdAt', 'totalAmount', 'status', 'reference'];
            $orderBy = in_array($orderBy, $allowedOrderBy) ? $orderBy : 'createdAt';
            $orderDir = in_array($orderDir, ['ASC', 'DESC']) ? $orderDir : 'DESC';

            // Construire la requête de base avec tous les JOIN nécessaires
            $qb = $this->orderRepository->createQueryBuilder('o')
                ->leftJoin('o.owner', 'u')->addSelect('u')
                ->leftJoin('o.items', 'oi')->addSelect('oi')
                ->leftJoin('o.carrier', 'c')->addSelect('c')
                ->leftJoin('o.shippingAddress', 'sa')->addSelect('sa');

            // ✅ FILTRE : Status
            if ($status) {
                $qb->andWhere('o.status = :status')
                   ->setParameter('status', $status);
            }

            // ✅ FILTRE : Transporteur (carrier code)
            if ($carrier) {
                $qb->andWhere('c.code = :carrier')
                   ->setParameter('carrier', $carrier);
                
                $this->logger->debug('Filter carrier applied', ['carrier' => $carrier]);
            }

            // ✅ FILTRE : Recherche (email, référence, nom client)
            if ($search) {
                $qb->andWhere(
                    $qb->expr()->orX(
                        $qb->expr()->like('o.reference', ':search'),
                        $qb->expr()->like('u.email', ':search'),
                        $qb->expr()->like('o.guestEmail', ':search'),
                        $qb->expr()->like('o.guestFirstName', ':search'),
                        $qb->expr()->like('o.guestLastName', ':search'),
                        $qb->expr()->like('u.firstName', ':search'),
                        $qb->expr()->like('u.lastName', ':search'),
                        $qb->expr()->like(
                            "CONCAT(COALESCE(u.firstName, o.guestFirstName), ' ', COALESCE(u.lastName, o.guestLastName))", 
                            ':search'
                        )
                    )
                )->setParameter('search', '%' . $search . '%');
            }

            // ✅ FILTRE : Intervalle de dates
            if ($startDate) {
                try {
                    $start = new \DateTime($startDate . ' 00:00:00');
                    $qb->andWhere('o.createdAt >= :startDate')
                       ->setParameter('startDate', $start);
                    
                    $this->logger->debug('Filter startDate applied', [
                        'startDate' => $startDate,
                        'parsed' => $start->format('Y-m-d H:i:s'),
                    ]);
                } catch (\Exception $e) {
                    $this->logger->warning('Invalid startDate format', [
                        'startDate' => $startDate,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($endDate) {
                try {
                    $end = new \DateTime($endDate . ' 23:59:59');
                    $qb->andWhere('o.createdAt <= :endDate')
                       ->setParameter('endDate', $end);
                    
                    $this->logger->debug('Filter endDate applied', [
                        'endDate' => $endDate,
                        'parsed' => $end->format('Y-m-d H:i:s'),
                    ]);
                } catch (\Exception $e) {
                    $this->logger->warning('Invalid endDate format', [
                        'endDate' => $endDate,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // ✅ FIX : Compter AVANT d'ajouter ORDER BY
            $totalItems = (int) (clone $qb)
                ->select('COUNT(DISTINCT o.id)')
                ->getQuery()
                ->getSingleScalarResult();

            // ✅ Ajouter ORDER BY après le count
            $qb->orderBy('o.' . $orderBy, $orderDir);

            // Récupérer les commandes
            $orders = $qb->setFirstResult($offset)
                ->setMaxResults($itemsPerPage)
                ->getQuery()
                ->getResult();

            // Formater les données
            $ordersData = [];
            foreach ($orders as $order) {
                // Déterminer l'email (owner ou guest)
                $customerEmail = $order->getOwner() 
                    ? $order->getOwner()->getEmail() 
                    : $order->getGuestEmail();

                // Déterminer le prénom (owner ou guest)
                $customerFirstName = $order->getOwner() 
                    ? $order->getOwner()->getFirstName() 
                    : $order->getGuestFirstName();

                // Déterminer le nom (owner ou guest)
                $customerLastName = $order->getOwner() 
                    ? $order->getOwner()->getLastName() 
                    : $order->getGuestLastName();

                $ordersData[] = [
                    'id' => $order->getId()->toRfc4122(),
                    'reference' => $order->getReference(),
                    'status' => $order->getStatus()->value,
                    'paymentStatus' => $order->getPaymentStatus(),
                    'total' => $order->getTotalAmount(),
                    'currency' => $order->getCurrency(),
                    'itemsCount' => $order->getItems()->count(),
                    'shippingMethod' => $order->getCarrier()?->getCode(),
                    'shippingCost' => $order->getShippingCost(),
                    'trackingNumber' => $order->getTrackingNumber(),
                    'customer' => [
                        'id' => $order->getOwner()?->getId()->toRfc4122(),
                        'email' => $customerEmail,
                        'firstName' => $customerFirstName,
                        'lastName' => $customerLastName,
                        'fullName' => trim($customerFirstName . ' ' . $customerLastName),
                    ],
                    'shipping' => [
                        'address' => $order->getShippingAddress()?->getStreetAddress(),
                        'city' => $order->getShippingAddress()?->getCity(),
                        'postalCode' => $order->getShippingAddress()?->getPostalCode(),
                        'country' => $order->getShippingAddress()?->getCountry(),
                    ],
                    'createdAt' => $order->getCreatedAt()?->format(\DateTime::ATOM),
                    'updatedAt' => $order->getUpdatedAt()?->format(\DateTime::ATOM),
                ];
            }

            return $this->json([
                'success' => true,
                'orders' => $ordersData,
                'pagination' => [
                    'currentPage' => $page,
                    'itemsPerPage' => $itemsPerPage,
                    'totalItems' => $totalItems,
                    'totalPages' => (int) ceil($totalItems / $itemsPerPage),
                ],
                'filters' => [
                    'status' => $status,
                    'carrier' => $carrier,
                    'search' => $search,
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                    'orderBy' => $orderBy,
                    'orderDir' => $orderDir,
                ],
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Get orders list failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la récupération des commandes'
            ], 500);
        }
    }
}