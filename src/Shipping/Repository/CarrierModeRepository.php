<?php

namespace App\Shipping\Repository;

use App\Shipping\Entity\CarrierMode;
use App\Shipping\Entity\Carrier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class CarrierModeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CarrierMode::class);
    }

    /**
     * supportedZones est un JSON ARRAY: ["FR","EU",...]
     */
    public function findByZone(string $zone): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<SQL
SELECT cm.id
FROM carrier_mode cm
JOIN carrier c ON c.id = cm.carrier_id
JOIN shipping_mode sm ON sm.id = cm.shipping_mode_id
WHERE cm.is_active = TRUE
  AND c.is_active = TRUE
  AND sm.is_active = TRUE
  AND (cm.supported_zones::jsonb @> :zoneJson::jsonb)
ORDER BY cm.base_price ASC
SQL;

        // JSON array contains: ["FR"]
        $zoneJson = json_encode([$zone], JSON_THROW_ON_ERROR);

        $ids = $conn->fetchFirstColumn($sql, [
            'zoneJson' => $zoneJson,
        ]);

        if (!$ids) {
            return [];
        }

        return $this->createQueryBuilder('cm')
            ->where('cm.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('cm.basePrice', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByCarrierAndZone(Carrier $carrier, string $zone): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<SQL
SELECT cm.id
FROM carrier_mode cm
JOIN shipping_mode sm ON sm.id = cm.shipping_mode_id
WHERE cm.carrier_id = :carrierId
  AND cm.is_active = TRUE
  AND sm.is_active = TRUE
  AND (cm.supported_zones::jsonb @> :zoneJson::jsonb)
ORDER BY cm.base_price ASC
SQL;

        $zoneJson = json_encode([$zone], JSON_THROW_ON_ERROR);

        $ids = $conn->fetchFirstColumn($sql, [
            'carrierId' => (string) $carrier->getId(),
            'zoneJson'  => $zoneJson,
        ]);

        if (!$ids) {
            return [];
        }

        return $this->createQueryBuilder('cm')
            ->where('cm.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('cm.basePrice', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
