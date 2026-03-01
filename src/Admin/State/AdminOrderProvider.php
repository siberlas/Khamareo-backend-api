<?php

namespace App\Admin\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\State\Pagination\PaginatorInterface;
use App\Order\Repository\OrderRepository;
use App\Shared\Enum\OrderStatus;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * State Provider pour la liste admin des commandes
 * Gère les filtres custom et la pagination
 */
class AdminOrderProvider implements ProviderInterface
{
    public function __construct(
        private OrderRepository $orderRepository,
        private RequestStack $requestStack
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $request = $this->requestStack->getCurrentRequest();
        
        if (!$request) {
            return [];
        }

        // Récupération des paramètres
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
        $offset = ($page - 1) * $limit;

        // Filtres
        $filters = [
            'status' => $request->query->get('status'),
            'carrier' => $request->query->get('carrier'),
            'search' => $request->query->get('search'),
            'dateFrom' => $request->query->get('dateFrom'),
            'dateTo' => $request->query->get('dateTo'),
        ];

        // Construction de la query
        $qb = $this->orderRepository->createQueryBuilder('o')
            ->leftJoin('o.owner', 'u')
            ->leftJoin('o.shippingAddress', 'sa')
            ->leftJoin('o.carrier', 'c')
            ->leftJoin('o.shippingMode', 'sm');

        // Filtre statut
        if ($filters['status']) {
            try {
                $statusEnum = OrderStatus::from($filters['status']);
                $qb->andWhere('o.status = :status')
                   ->setParameter('status', $statusEnum);
            } catch (\ValueError $e) {
                // Statut invalide, on ignore
            }
        }

        // Filtre transporteur
        if ($filters['carrier']) {
            $qb->andWhere('c.code = :carrier')
               ->setParameter('carrier', $filters['carrier']);
        }

        // Recherche texte
        if ($filters['search']) {
            $qb->andWhere('
                o.orderNumber LIKE :search 
                OR o.reference LIKE :search
                OR u.email LIKE :search 
                OR u.firstName LIKE :search 
                OR u.lastName LIKE :search
                OR o.guestEmail LIKE :search
                OR o.guestFirstName LIKE :search
                OR o.guestLastName LIKE :search
                OR sa.firstName LIKE :search
                OR sa.lastName LIKE :search
            ')
               ->setParameter('search', '%' . $filters['search'] . '%');
        }

        // Filtre date début
        if ($filters['dateFrom']) {
            try {
                $dateFrom = new \DateTime($filters['dateFrom']);
                $dateFrom->setTime(0, 0, 0);
                $qb->andWhere('o.createdAt >= :dateFrom')
                   ->setParameter('dateFrom', $dateFrom);
            } catch (\Exception $e) {
                // Date invalide, on ignore
            }
        }

        // Filtre date fin
        if ($filters['dateTo']) {
            try {
                $dateTo = new \DateTime($filters['dateTo']);
                $dateTo->setTime(23, 59, 59);
                $qb->andWhere('o.createdAt <= :dateTo')
                   ->setParameter('dateTo', $dateTo);
            } catch (\Exception $e) {
                // Date invalide, on ignore
            }
        }

        // Compte total
        $totalQb = clone $qb;
        $total = (int) $totalQb->select('COUNT(o.id)')->getQuery()->getSingleScalarResult();

        // Récupération des résultats avec pagination
        $orders = $qb
            ->orderBy('o.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        // Retour avec pagination
        return new AdminOrderPaginator($orders, $page, $limit, $total);
    }
}

/**
 * Paginator personnalisé pour les commandes admin
 */
class AdminOrderPaginator implements PaginatorInterface, \IteratorAggregate
{
    public function __construct(
        private array $items,
        private int $currentPage,
        private int $itemsPerPage,
        private int $totalItems
    ) {}

    public function getCurrentPage(): float
    {
        return (float) $this->currentPage;
    }

    public function getLastPage(): float
    {
        return (float) ceil($this->totalItems / $this->itemsPerPage);
    }

    public function getItemsPerPage(): float
    {
        return (float) $this->itemsPerPage;
    }

    public function getTotalItems(): float
    {
        return (float) $this->totalItems;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }
}