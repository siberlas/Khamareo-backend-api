<?php

namespace App\Admin\Controller\Shipping;

use App\Shared\Entity\StoreSettings;
use App\Shipping\Entity\CarrierMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
#[Route('/api/admin', name: 'admin_shipping_config_')]
class ShippingConfigController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('/shipping-config', name: 'get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return $this->json($this->serialize($this->getOrCreate()));
    }

    #[Route('/shipping-config', name: 'update', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $settings = $this->getOrCreate();

        if (array_key_exists('caePercentRoutier', $data)) {
            $settings->setCaePercentRoutier($data['caePercentRoutier'] !== null && $data['caePercentRoutier'] !== '' ? (float) $data['caePercentRoutier'] : null);
        }
        if (array_key_exists('caePercentAerien', $data)) {
            $settings->setCaePercentAerien($data['caePercentAerien'] !== null && $data['caePercentAerien'] !== '' ? (float) $data['caePercentAerien'] : null);
        }
        if (array_key_exists('caeExcludedCarrierModeIds', $data) && is_array($data['caeExcludedCarrierModeIds'])) {
            $settings->setCaeExcludedCarrierModeIds(array_map('intval', $data['caeExcludedCarrierModeIds']));
        }
        if (array_key_exists('supplementInternationalSecurity', $data)) {
            $settings->setSupplementInternationalSecurity($data['supplementInternationalSecurity'] !== null && $data['supplementInternationalSecurity'] !== '' ? (float) $data['supplementInternationalSecurity'] : null);
        }
        if (array_key_exists('supplementUs', $data)) {
            $settings->setSupplementUs($data['supplementUs'] !== null && $data['supplementUs'] !== '' ? (float) $data['supplementUs'] : null);
        }
        if (array_key_exists('supplementChina', $data)) {
            $settings->setSupplementChina($data['supplementChina'] !== null && $data['supplementChina'] !== '' ? (float) $data['supplementChina'] : null);
        }
        if (array_key_exists('supplementUk', $data)) {
            $settings->setSupplementUk($data['supplementUk'] !== null && $data['supplementUk'] !== '' ? (float) $data['supplementUk'] : null);
        }
        if (array_key_exists('supplementDecarbonation', $data)) {
            $settings->setSupplementDecarbonation($data['supplementDecarbonation'] !== null && $data['supplementDecarbonation'] !== '' ? (float) $data['supplementDecarbonation'] : null);
        }
        if (array_key_exists('smicCompensationPercent', $data)) {
            $settings->setSmicCompensationPercent($data['smicCompensationPercent'] !== null && $data['smicCompensationPercent'] !== '' ? (float) $data['smicCompensationPercent'] : null);
        }
        if (array_key_exists('shippingVatRatePercent', $data)) {
            $settings->setShippingVatRatePercent($data['shippingVatRatePercent'] !== null && $data['shippingVatRatePercent'] !== '' ? (float) $data['shippingVatRatePercent'] : null);
        }

        $settings->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->json($this->serialize($settings));
    }

    #[Route('/carrier-modes', name: 'carrier_modes', methods: ['GET'])]
    public function carrierModes(): JsonResponse
    {
        $carrierModes = $this->em->getRepository(CarrierMode::class)->findAll();

        return $this->json([
            'carrierModes' => array_map(function (CarrierMode $cm) {
                // Plusieurs carrier_mode peuvent partager le même nom de mode
                // (ex: 5x "Domicile" chez Colissimo, un par zone) — on précise
                // la zone/le code produit pour les distinguer dans la liste.
                $name = $cm->getShippingMode()?->getName() ?? '';
                $detail = $cm->getColissimoProductCodeKey() ?: implode('/', $cm->getSupportedZones() ?? []);
                return [
                    'id' => $cm->getId(),
                    'name' => $detail ? "{$name} ({$detail})" : $name,
                    'carrierName' => $cm->getCarrier()?->getName(),
                    'energyCoefficientType' => $cm->getEnergyCoefficientType(),
                    'isActive' => $cm->isActive(),
                ];
            }, $carrierModes),
        ]);
    }

    #[Route('/carrier-modes/{id}', name: 'update_carrier_mode', methods: ['PATCH'])]
    public function updateCarrierMode(int $id, Request $request): JsonResponse
    {
        $carrierMode = $this->em->getRepository(CarrierMode::class)->find($id);
        if (!$carrierMode) {
            return $this->json(['error' => 'Mode de transport introuvable'], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        if (array_key_exists('energyCoefficientType', $data)) {
            $type = $data['energyCoefficientType'];
            if ($type !== null && !in_array($type, ['routier', 'aerien'], true)) {
                return $this->json(['error' => 'energyCoefficientType doit être "routier", "aerien" ou null'], 400);
            }
            $carrierMode->setEnergyCoefficientType($type);
        }
        if (array_key_exists('isActive', $data)) {
            $carrierMode->setIsActive((bool) $data['isActive']);
        }
        $this->em->flush();

        return $this->json([
            'success' => true,
            'id' => $carrierMode->getId(),
            'energyCoefficientType' => $carrierMode->getEnergyCoefficientType(),
            'isActive' => $carrierMode->isActive(),
        ]);
    }

    private function getOrCreate(): StoreSettings
    {
        $settings = $this->em->getRepository(StoreSettings::class)->findOneBy([]);
        if (!$settings) {
            $settings = new StoreSettings();
            $this->em->persist($settings);
            $this->em->flush();
        }
        return $settings;
    }

    private function serialize(StoreSettings $s): array
    {
        return [
            'caePercentRoutier' => $s->getCaePercentRoutier(),
            'caePercentAerien' => $s->getCaePercentAerien(),
            'caeExcludedCarrierModeIds' => $s->getCaeExcludedCarrierModeIds(),
            'supplementInternationalSecurity' => $s->getSupplementInternationalSecurity(),
            'supplementUs' => $s->getSupplementUs(),
            'supplementChina' => $s->getSupplementChina(),
            'supplementUk' => $s->getSupplementUk(),
            'supplementDecarbonation' => $s->getSupplementDecarbonation(),
            'smicCompensationPercent' => $s->getSmicCompensationPercent(),
            'shippingVatRatePercent' => $s->getShippingVatRatePercent(),
        ];
    }
}
