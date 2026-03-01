<?php

namespace App\Admin\Controller\User;

use App\User\Repository\UserRepository;
use App\Order\Repository\OrderRepository;
use App\Order\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Uid\Uuid;

#[AsController]
#[Route('/api/admin/users', name: 'admin_users_')]
class UserAdminController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private OrderRepository $orderRepository,
        private LoggerInterface $logger
    ) {}

    /**
     * Statistiques utilisateurs
     * 
     * GET /api/admin/users/stats?period=30days
     */
    #[Route('/stats', name: 'stats', methods: ['GET'])]
    public function getStats(Request $request): JsonResponse
    {
        try {
            $period = $request->query->get('period', '30days');

            $endDate = new \DateTimeImmutable('now');
            $startDate = match($period) {
                '7days' => $endDate->modify('-7 days'),
                '30days' => $endDate->modify('-30 days'),
                '90days' => $endDate->modify('-90 days'),
                '1year' => $endDate->modify('-1 year'),
                'all' => null,
                default => $endDate->modify('-30 days'),
            };

            $totalUsers = (int) $this->userRepository->createQueryBuilder('u')
                ->select('COUNT(u.id)')
                ->getQuery()->getSingleScalarResult();

            $registeredUsers = (int) $this->userRepository->createQueryBuilder('u')
                ->select('COUNT(u.id)')
                ->andWhere('u.isGuest = false')
                ->getQuery()->getSingleScalarResult();

            $guestUsers = (int) $this->userRepository->createQueryBuilder('u')
                ->select('COUNT(u.id)')
                ->andWhere('u.isGuest = true')
                ->getQuery()->getSingleScalarResult();

            $verifiedUsers = (int) $this->userRepository->createQueryBuilder('u')
                ->select('COUNT(u.id)')
                ->andWhere('u.isVerified = true')
                ->getQuery()->getSingleScalarResult();

            $unverifiedUsers = (int) $this->userRepository->createQueryBuilder('u')
                ->select('COUNT(u.id)')
                ->andWhere('u.isVerified = false')
                ->getQuery()->getSingleScalarResult();

            $newsletterUsers = (int) $this->userRepository->createQueryBuilder('u')
                ->select('COUNT(u.id)')
                ->andWhere('u.newsletter = true')
                ->getQuery()->getSingleScalarResult();

            $adminUsers = 0;
            $rolesRows = $this->userRepository->createQueryBuilder('u')
                ->select('u.roles')
                ->getQuery()->getArrayResult();
            foreach ($rolesRows as $row) {
                $roles = $row['roles'] ?? [];
                if (is_array($roles) && in_array('ROLE_ADMIN', $roles, true)) {
                    $adminUsers++;
                }
            }

            $newUsersQb = $this->userRepository->createQueryBuilder('u')
                ->select('COUNT(u.id)');
            if ($startDate) {
                $newUsersQb->andWhere('u.createdAt >= :startDate')
                           ->setParameter('startDate', $startDate);
            }
            $newUsers = (int) $newUsersQb->getQuery()->getSingleScalarResult();

            $activeUsersQb = $this->em->createQueryBuilder()
                ->select('COUNT(DISTINCT u.id)')
                ->from(Order::class, 'o')
                ->innerJoin('o.owner', 'u');
            if ($startDate) {
                $activeUsersQb->andWhere('o.createdAt >= :startDate')
                              ->setParameter('startDate', $startDate);
            }
            $activeUsers = (int) $activeUsersQb->getQuery()->getSingleScalarResult();

            $usersWithOrdersQb = $this->em->createQueryBuilder()
                ->select('COUNT(DISTINCT u2.id)')
                ->from(Order::class, 'o2')
                ->innerJoin('o2.owner', 'u2');
            $usersWithOrders = (int) $usersWithOrdersQb->getQuery()->getSingleScalarResult();

            $inactiveUsers = max(0, $registeredUsers - $usersWithOrders);
            $abandonmentRate = $registeredUsers > 0
                ? round(($inactiveUsers / $registeredUsers) * 100, 2)
                : 0.0;

            $newsletterCustomersQb = $this->em->createQueryBuilder()
                ->select('COUNT(DISTINCT u3.id)')
                ->from(Order::class, 'o3')
                ->innerJoin('o3.owner', 'u3')
                ->andWhere('u3.newsletter = true');
            $newsletterCustomers = (int) $newsletterCustomersQb->getQuery()->getSingleScalarResult();
            $newsletterConversionRate = $newsletterUsers > 0
                ? round(($newsletterCustomers / $newsletterUsers) * 100, 2)
                : 0.0;

            $totalOrders = $this->orderRepository->countOrdersByPeriod($startDate);
            $totalRevenue = $this->orderRepository->getTotalRevenue($startDate);
            $averageOrderValue = $this->orderRepository->getAverageOrderValue($startDate);

            $averageCustomerValue = $usersWithOrders > 0
                ? round($totalRevenue / $usersWithOrders, 2)
                : 0.0;

            $topCustomersQb = $this->em->createQueryBuilder()
                ->select('u.id AS id, u.email AS email, u.firstName AS firstName, u.lastName AS lastName, COUNT(o.id) AS ordersCount, COALESCE(SUM(o.totalAmount), 0) AS totalSpent')
                ->from(Order::class, 'o')
                ->innerJoin('o.owner', 'u')
                ->groupBy('u.id')
                ->orderBy('totalSpent', 'DESC')
                ->setMaxResults(5);

            $topCustomers = array_map(static function(array $row): array {
                return [
                    'id' => $row['id'],
                    'email' => $row['email'],
                    'firstName' => $row['firstName'],
                    'lastName' => $row['lastName'],
                    'ordersCount' => (int) $row['ordersCount'],
                    'totalSpent' => (float) $row['totalSpent'],
                ];
            }, $topCustomersQb->getQuery()->getScalarResult());

            return $this->json([
                'success' => true,
                'stats' => [
                    'totalUsers' => $totalUsers,
                    'registeredUsers' => $registeredUsers,
                    'guestUsers' => $guestUsers,
                    'newUsers' => $newUsers,
                    'activeUsers' => $activeUsers,
                    'verifiedUsers' => $verifiedUsers,
                    'unverifiedUsers' => $unverifiedUsers,
                    'adminUsers' => $adminUsers,
                    'newsletterUsers' => $newsletterUsers,
                    'newsletterCustomers' => $newsletterCustomers,
                    'newsletterConversionRate' => $newsletterConversionRate,
                    'usersWithOrders' => $usersWithOrders,
                    'inactiveUsers' => $inactiveUsers,
                    'abandonmentRate' => $abandonmentRate,
                    'totalOrders' => $totalOrders,
                    'totalRevenue' => $totalRevenue,
                    'averageOrderValue' => $averageOrderValue,
                    'averageCustomerValue' => $averageCustomerValue,
                    'topCustomers' => $topCustomers,
                    'period' => $period,
                    'periodStart' => $startDate?->format(\DateTime::ATOM),
                    'periodEnd' => $endDate->format(\DateTime::ATOM),
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Get users stats failed', [
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
     * Liste des utilisateurs avec pagination et filtres
     * 
     * GET /api/admin/users?page=1&itemsPerPage=20&search=john&role=ROLE_USER&verified=true&newsletter=true&isGuest=false&hasOrders=true&startDate=2025-01-01&endDate=2025-01-31&orderBy=createdAt&orderDir=desc
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $page = max(1, $request->query->getInt('page', 1));
            $itemsPerPage = min(100, max(1, $request->query->getInt('itemsPerPage', 20)));
            $offset = ($page - 1) * $itemsPerPage;

            $search = $request->query->get('search');
            $role = $request->query->get('role');
            $verified = $this->parseBool($request->query->get('verified'));
            $newsletter = $this->parseBool($request->query->get('newsletter'));
            $isGuest = $this->parseBool($request->query->get('isGuest'));
            $hasOrders = $this->parseBool($request->query->get('hasOrders'));
            $startDate = $request->query->get('startDate');
            $endDate = $request->query->get('endDate');

            $orderBy = $request->query->get('orderBy', 'createdAt');
            $orderDir = strtoupper($request->query->get('orderDir', 'DESC'));

            $allowedOrderBy = ['createdAt', 'lastName', 'ordersCount', 'totalSpent'];
            $orderBy = in_array($orderBy, $allowedOrderBy, true) ? $orderBy : 'createdAt';
            $orderDir = in_array($orderDir, ['ASC', 'DESC'], true) ? $orderDir : 'DESC';

            $qb = $this->userRepository->createQueryBuilder('u')
                ->leftJoin(Order::class, 'o', 'WITH', 'o.owner = u')
                ->addSelect('COUNT(o.id) AS ordersCount')
                ->addSelect('COALESCE(SUM(o.totalAmount), 0) AS totalSpent')
                ->groupBy('u.id');

            $this->applyUserFilters($qb, $search, $verified, $newsletter, $isGuest, $startDate, $endDate);

            if ($hasOrders !== null) {
                if ($hasOrders) {
                    $qb->having('COUNT(o.id) > 0');
                } else {
                    $qb->having('COUNT(o.id) = 0');
                }
            }

            $orderMap = [
                'createdAt' => 'u.createdAt',
                'lastName' => 'u.lastName',
                'ordersCount' => 'ordersCount',
                'totalSpent' => 'totalSpent',
            ];
            $qb->orderBy($orderMap[$orderBy], $orderDir);

            if ($role) {
                $rows = $qb->getQuery()->getResult();
                $rows = $this->filterRowsByRole($rows, $role);
                $totalItems = count($rows);
                $rows = array_slice($rows, $offset, $itemsPerPage);
            } else {
                $qb->setFirstResult($offset)
                   ->setMaxResults($itemsPerPage);
                $rows = $qb->getQuery()->getResult();
                $totalItems = $this->countUsersWithFilters($search, $verified, $newsletter, $isGuest, $hasOrders, $startDate, $endDate);
            }

            $users = array_map(static function($row): array {
                $user = is_array($row) ? $row[0] : $row;
                $ordersCount = is_array($row) && isset($row['ordersCount']) ? (int) $row['ordersCount'] : 0;
                $totalSpent = is_array($row) && isset($row['totalSpent']) ? (float) $row['totalSpent'] : 0.0;

                return [
                    'id' => (string) $user->getId(),
                    'email' => $user->getEmail(),
                    'firstName' => $user->getFirstName(),
                    'lastName' => $user->getLastName(),
                    'phone' => $user->getPhone(),
                    'roles' => $user->getRoles(),
                    'isVerified' => $user->isVerified(),
                    'isGuest' => $user->isGuest(),
                    'newsletter' => $user->isNewsletter(),
                    'createdAt' => $user->getCreatedAt()?->format(\DateTime::ATOM),
                    'ordersCount' => $ordersCount,
                    'totalSpent' => $totalSpent,
                ];
            }, $rows);

            return $this->json([
                'success' => true,
                'page' => $page,
                'itemsPerPage' => $itemsPerPage,
                'totalItems' => $totalItems,
                'users' => $users,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Get users list failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la récupération des utilisateurs'
            ], 500);
        }
    }

    /**
     * Détails utilisateur + commandes
     * 
     * GET /api/admin/users/{id}
     */
    #[Route('/{id}', name: 'details', methods: ['GET'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    public function details(string $id): JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);

            $user = $this->userRepository->createQueryBuilder('u')
                ->leftJoin('u.addresses', 'a')->addSelect('a')
                ->where('u.id = :id')
                ->setParameter('id', $uuid)
                ->getQuery()
                ->getOneOrNullResult();

            if (!$user) {
                return $this->json([
                    'success' => false,
                    'error' => 'Utilisateur introuvable'
                ], 404);
            }

            $orders = $this->orderRepository->createQueryBuilder('o')
                ->select('o')
                ->where('o.owner = :user')
                ->setParameter('user', $user)
                ->orderBy('o.createdAt', 'DESC')
                ->getQuery()
                ->getResult();

            $ordersData = array_map(static function(Order $order): array {
                return [
                    'id' => (string) $order->getId(),
                    'reference' => $order->getReference(),
                    'orderNumber' => $order->getOrderNumber(),
                    'status' => $order->getStatus()->value,
                    'statusLabel' => $order->getStatusLabel(),
                    'totalAmount' => $order->getTotalAmount(),
                    'shippingCost' => $order->getShippingCost(),
                    'currency' => $order->getCurrency(),
                    'createdAt' => $order->getCreatedAt()?->format(\DateTime::ATOM),
                ];
            }, $orders);

            $addresses = [];
            foreach ($user->getAddresses() as $address) {
                $addresses[] = [
                    'id' => $address->getId(),
                    'addressKind' => $address->getAddressKind(),
                    'isRelayPoint' => $address->isRelayPoint(),
                    'relayPointId' => $address->getRelayPointId(),
                    'relayCarrier' => $address->getRelayCarrier(),
                    'firstName' => $address->getFirstName(),
                    'lastName' => $address->getLastName(),
                    'street' => $address->getStreetAddress(),
                    'city' => $address->getCity(),
                    'postalCode' => $address->getPostalCode(),
                    'country' => $address->getCountry(),
                    'phone' => $address->getPhone(),
                ];
            }

            $userData = [
                'id' => (string) $user->getId(),
                'email' => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'phone' => $user->getPhone(),
                'roles' => $user->getRoles(),
                'isVerified' => $user->isVerified(),
                'isGuest' => $user->isGuest(),
                'newsletter' => $user->isNewsletter(),
                'createdAt' => $user->getCreatedAt()?->format(\DateTime::ATOM),
                'addresses' => $addresses,
            ];

            return $this->json([
                'success' => true,
                'user' => $userData,
                'orders' => $ordersData,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Get user details failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la récupération de l\'utilisateur'
            ], 500);
        }
    }

    private function parseBool(?string $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    private function applyUserFilters(
        QueryBuilder $qb,
        ?string $search,
        ?bool $verified,
        ?bool $newsletter,
        ?bool $isGuest,
        ?string $startDate,
        ?string $endDate
    ): void {
        if ($search) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('u.email', ':search'),
                    $qb->expr()->like('u.firstName', ':search'),
                    $qb->expr()->like('u.lastName', ':search'),
                    $qb->expr()->like('u.phone', ':search')
                )
            )->setParameter('search', '%' . $search . '%');
        }

        if ($verified !== null) {
            $qb->andWhere('u.isVerified = :verified')
               ->setParameter('verified', $verified);
        }

        if ($newsletter !== null) {
            $qb->andWhere('u.newsletter = :newsletter')
               ->setParameter('newsletter', $newsletter);
        }

        if ($isGuest !== null) {
            $qb->andWhere('u.isGuest = :isGuest')
               ->setParameter('isGuest', $isGuest);
        }

        if ($startDate) {
            try {
                $start = new \DateTime($startDate . ' 00:00:00');
                $qb->andWhere('u.createdAt >= :startDate')
                   ->setParameter('startDate', $start);
            } catch (\Exception $e) {
            }
        }

        if ($endDate) {
            try {
                $end = new \DateTime($endDate . ' 23:59:59');
                $qb->andWhere('u.createdAt <= :endDate')
                   ->setParameter('endDate', $end);
            } catch (\Exception $e) {
            }
        }
    }

    private function countUsersWithFilters(
        ?string $search,
        ?bool $verified,
        ?bool $newsletter,
        ?bool $isGuest,
        ?bool $hasOrders,
        ?string $startDate,
        ?string $endDate
    ): int {
        $qb = $this->userRepository->createQueryBuilder('u')
            ->select('COUNT(DISTINCT u.id)');

        if ($hasOrders === true) {
            $qb->innerJoin(Order::class, 'o', 'WITH', 'o.owner = u');
        } elseif ($hasOrders === false) {
            $qb->leftJoin(Order::class, 'o', 'WITH', 'o.owner = u')
               ->andWhere('o.id IS NULL');
        }

        $this->applyUserFilters($qb, $search, $verified, $newsletter, $isGuest, $startDate, $endDate);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<int, mixed>
     */
    private function filterRowsByRole(array $rows, string $role): array
    {
        return array_values(array_filter($rows, static function($row) use ($role): bool {
            $user = is_array($row) ? $row[0] : $row;
            if (!method_exists($user, 'getRoles')) {
                return false;
            }
            return in_array($role, $user->getRoles(), true);
        }));
    }
}
