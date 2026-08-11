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

        if (array_key_exists('caePercent', $data)) {
            $settings->setCaePercent($data['caePercent'] !== null && $data['caePercent'] !== '' ? (float) $data['caePercent'] : null);
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
        if (array_key_exists('supplementDecarbonation', $data)) {
            $settings->setSupplementDecarbonation($data['supplementDecarbonation'] !== null && $data['supplementDecarbonation'] !== '' ? (float) $data['supplementDecarbonation'] : null);
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
                ];
            }, $carrierModes),
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
            'caePercent' => $s->getCaePercent(),
            'caeExcludedCarrierModeIds' => $s->getCaeExcludedCarrierModeIds(),
            'supplementInternationalSecurity' => $s->getSupplementInternationalSecurity(),
            'supplementUs' => $s->getSupplementUs(),
            'supplementDecarbonation' => $s->getSupplementDecarbonation(),
        ];
    }
}
