<?php

namespace App\Admin\Controller\Analytics;

use App\Cart\Entity\Cart;
use App\Marketing\Entity\PromoCodeRedemption;
use App\Order\Entity\Order;
use App\Order\Entity\OrderItem;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
#[Route('/api/admin/analytics', name: 'admin_analytics_')]
class AnalyticsController extends AbstractController
{
    private const COUNTRY_CODES = [
        'France' => 'FR', 'Belgique' => 'BE', 'Suisse' => 'CH',
        'Allemagne' => 'DE', 'Espagne' => 'ES', 'Italie' => 'IT',
        'Royaume-Uni' => 'GB', 'Pays-Bas' => 'NL', 'Portugal' => 'PT',
        'Autriche' => 'AT', 'Luxembourg' => 'LU', 'Suède' => 'SE',
        'Norvège' => 'NO', 'Danemark' => 'DK', 'Finlande' => 'FI',
        'Sénégal' => 'SN', "Côte d'Ivoire" => 'CI', 'Cameroun' => 'CM',
        'Mali' => 'ML', 'Guinée' => 'GN', 'Maroc' => 'MA',
        'Tunisie' => 'TN', 'Algérie' => 'DZ', 'Congo' => 'CG',
        'Gabon' => 'GA', 'Togo' => 'TG', 'Bénin' => 'BJ',
        'Burkina Faso' => 'BF', 'Niger' => 'NE', 'Madagascar' => 'MG',
        'Maurice' => 'MU', 'Réunion' => 'RE', 'Martinique' => 'MQ',
        'Guadeloupe' => 'GP', 'Canada' => 'CA', 'États-Unis' => 'US',
        // English variants
        'Belgium' => 'BE', 'Switzerland' => 'CH', 'Germany' => 'DE',
        'Spain' => 'ES', 'Italy' => 'IT', 'United Kingdom' => 'GB',
        'Netherlands' => 'NL', 'Senegal' => 'SN', 'Ivory Coast' => 'CI',
        'Cameroon' => 'CM', 'Guinea' => 'GN', 'Morocco' => 'MA',
        'Tunisia' => 'TN', 'Algeria' => 'DZ', 'United States' => 'US',
        'Benin' => 'BJ', 'Mauritius' => 'MU',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {}

    private function parseStartDate(string $period): ?\DateTimeImmutable
    {
        $now = new \DateTimeImmutable();
        return match ($period) {
            '7days'  => $now->modify('-7 days'),
            '30days' => $now->modify('-30 days'),
            '90days' => $now->modify('-90 days'),
            '1year'  => $now->modify('-1 year'),
            'all'    => null,
            default  => $now->modify('-30 days'),
        };
    }

    #[Route('/funnel', name: 'funnel', methods: ['GET'])]
    public function funnel(Request $request): JsonResponse
    {
        try {
            $startDate = $this->parseStartDate($request->query->get('period', '30days'));

            $cartQb = fn() => $this->em->createQueryBuilder()
                ->from(Cart::class, 'c')
                ->innerJoin('c.items', 'ci')
                ->select('COUNT(DISTINCT c.id)');

            $withSince = function ($qb) use ($startDate) {
                if ($startDate) {
                    $qb->andWhere('c.createdAt >= :since')->setParameter('since', $startDate);
                }
                return $qb;
            };

            $cartsWithItems = (int) $withSince($cartQb())->getQuery()->getSingleScalarResult();

            $cartsPaymentStarted = (int) $withSince($cartQb())
                ->andWhere('c.paymentIntentId IS NOT NULL')
                ->getQuery()->getSingleScalarResult();

            $orderQb = fn() => $this->em->createQueryBuilder()
                ->from(Order::class, 'o')
                ->andWhere('o.isTest = :isTest')
                ->setParameter('isTest', false);

            $withOrderSince = function ($qb) use ($startDate) {
                if ($startDate) {
                    $qb->andWhere('o.createdAt >= :since')->setParameter('since', $startDate);
                }
                return $qb;
            };

            $ordersCreated = (int) $withOrderSince($orderQb()->select('COUNT(DISTINCT o.id)'))->getQuery()->getSingleScalarResult();

            $ordersPaidQb = $withOrderSince($orderQb()->select('COUNT(DISTINCT o.id)'))
                ->andWhere("o.paymentStatus = 'paid'");
            $ordersPaid = (int) $ordersPaidQb->getQuery()->getSingleScalarResult();

            $revenueQb = $withOrderSince($orderQb()->select('COALESCE(SUM(o.totalAmount), 0)'))
                ->andWhere("o.paymentStatus = 'paid'");
            $revenueTotal = round((float) $revenueQb->getQuery()->getSingleScalarResult(), 2);

            return $this->json([
                'success' => true,
                'funnel' => [
                    'cartsWithItems'      => $cartsWithItems,
                    'cartsPaymentStarted' => $cartsPaymentStarted,
                    'ordersCreated'       => $ordersCreated,
                    'ordersPaid'          => $ordersPaid,
                    'revenueTotal'        => $revenueTotal,
                    'conversionRate'      => $cartsWithItems > 0 ? round($ordersPaid / $cartsWithItems * 100, 1) : 0,
                    'paymentSuccessRate'  => $cartsPaymentStarted > 0 ? round($ordersPaid / $cartsPaymentStarted * 100, 1) : 0,
                ],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Analytics funnel failed', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'error' => 'Erreur funnel'], 500);
        }
    }

    #[Route('/top-products', name: 'top_products', methods: ['GET'])]
    public function topProducts(Request $request): JsonResponse
    {
        try {
            $startDate = $this->parseStartDate($request->query->get('period', '30days'));
            $limit = min(50, max(5, $request->query->getInt('limit', 10)));

            $qb = $this->em->createQueryBuilder()
                ->select('p.name, p.slug, SUM(oi.quantity) as totalQty, SUM(oi.quantity * oi.unitPrice) as totalRevenue, COUNT(DISTINCT o.id) as ordersCount')
                ->from(OrderItem::class, 'oi')
                ->join('oi.customerOrder', 'o')
                ->join('oi.product', 'p')
                ->andWhere("o.paymentStatus = 'paid'")
                ->andWhere('o.isTest = :isTest')
                ->setParameter('isTest', false)
                ->groupBy('p.id, p.name, p.slug')
                ->orderBy('totalRevenue', 'DESC')
                ->setMaxResults($limit);

            if ($startDate) {
                $qb->andWhere('o.createdAt >= :since')->setParameter('since', $startDate);
            }

            $rows = $qb->getQuery()->getArrayResult();
            $maxRevenue = !empty($rows) ? (float) $rows[0]['totalRevenue'] : 1;

            $products = array_map(fn ($r) => [
                'name'         => $r['name'],
                'slug'         => $r['slug'],
                'totalQty'     => (int) $r['totalQty'],
                'totalRevenue' => round((float) $r['totalRevenue'], 2),
                'ordersCount'  => (int) $r['ordersCount'],
                'share'        => $maxRevenue > 0 ? round((float) $r['totalRevenue'] / $maxRevenue * 100, 1) : 0,
            ], $rows);

            return $this->json(['success' => true, 'products' => $products, 'total' => count($products)]);
        } catch (\Exception $e) {
            $this->logger->error('Analytics top-products failed', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'error' => 'Erreur top produits'], 500);
        }
    }

    #[Route('/geography', name: 'geography', methods: ['GET'])]
    public function geography(Request $request): JsonResponse
    {
        try {
            $startDate = $this->parseStartDate($request->query->get('period', '30days'));

            $qb = $this->em->createQueryBuilder()
                ->select('sa.country, sa.city, COUNT(DISTINCT o.id) as ordersCount, COALESCE(SUM(o.totalAmount), 0) as revenue')
                ->from(Order::class, 'o')
                ->join('o.shippingAddress', 'sa')
                ->andWhere("o.paymentStatus = 'paid'")
                ->andWhere('o.isTest = :isTest')
                ->setParameter('isTest', false)
                ->groupBy('sa.country, sa.city')
                ->orderBy('ordersCount', 'DESC');

            if ($startDate) {
                $qb->andWhere('o.createdAt >= :since')->setParameter('since', $startDate);
            }

            $rows = $qb->getQuery()->getArrayResult();

            $byCountry = [];
            $totalOrders = 0;
            $totalRevenue = 0.0;

            foreach ($rows as $row) {
                $country = $row['country'] ?: 'Inconnu';
                $revenue = (float) $row['revenue'];
                $orders  = (int) $row['ordersCount'];

                if (!isset($byCountry[$country])) {
                    $byCountry[$country] = [
                        'country'     => $country,
                        'countryCode' => preg_match('/^[A-Z]{2}$/', $country) ? $country : (self::COUNTRY_CODES[$country] ?? null),
                        'ordersCount' => 0,
                        'revenue'     => 0.0,
                        'cities'      => [],
                    ];
                }

                $byCountry[$country]['ordersCount'] += $orders;
                $byCountry[$country]['revenue']     += $revenue;
                $byCountry[$country]['cities'][]     = [
                    'city'        => $row['city'] ?: 'Inconnu',
                    'ordersCount' => $orders,
                    'revenue'     => round($revenue, 2),
                ];
                $totalOrders  += $orders;
                $totalRevenue += $revenue;
            }

            usort($byCountry, fn ($a, $b) => $b['ordersCount'] <=> $a['ordersCount']);

            foreach ($byCountry as &$c) {
                $c['revenue']    = round($c['revenue'], 2);
                $c['percentage'] = $totalOrders > 0 ? round($c['ordersCount'] / $totalOrders * 100, 1) : 0;
            }

            return $this->json([
                'success'      => true,
                'countries'    => array_values($byCountry),
                'totalOrders'  => $totalOrders,
                'totalRevenue' => round($totalRevenue, 2),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Analytics geography failed', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'error' => 'Erreur géographie'], 500);
        }
    }

    #[Route('/promo-performance', name: 'promo_performance', methods: ['GET'])]
    public function promoPerformance(Request $request): JsonResponse
    {
        try {
            $startDate = $this->parseStartDate($request->query->get('period', '30days'));

            $qb = $this->em->createQueryBuilder()
                ->select('pc.code, pc.type, COUNT(r.id) as usageCount, COALESCE(SUM(r.discountAmount), 0) as totalDiscount, COALESCE(SUM(o.totalAmount), 0) as totalRevenue')
                ->from(PromoCodeRedemption::class, 'r')
                ->join('r.promoCode', 'pc')
                ->leftJoin('r.order', 'o')
                ->groupBy('pc.id, pc.code, pc.type')
                ->orderBy('usageCount', 'DESC');

            if ($startDate) {
                $qb->andWhere('r.usedAt >= :since')->setParameter('since', $startDate);
            }

            $rows = $qb->getQuery()->getArrayResult();

            $promos = array_map(fn ($r) => [
                'code'          => $r['code'],
                'type'          => $r['type'],
                'usageCount'    => (int) $r['usageCount'],
                'totalDiscount' => round((float) $r['totalDiscount'], 2),
                'totalRevenue'  => round((float) $r['totalRevenue'], 2),
                'roi'           => (float) $r['totalDiscount'] > 0
                    ? round((float) $r['totalRevenue'] / (float) $r['totalDiscount'], 1)
                    : 0,
            ], $rows);

            return $this->json(['success' => true, 'promos' => $promos, 'total' => count($promos)]);
        } catch (\Exception $e) {
            $this->logger->error('Analytics promo-performance failed', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'error' => 'Erreur promo performance'], 500);
        }
    }
}
