<?php

namespace App\Shared\Controller;

use App\Shared\Entity\StoreSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
class StoreSettingsController
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    #[Route('/api/public/store-settings', name: 'store_settings_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return new JsonResponse($this->serialize($this->getOrCreate()));
    }

    #[Route('/api/admin/store-settings', name: 'admin_store_settings_update', methods: ['PUT'])]
    public function update(Request $request): JsonResponse
    {
        $data     = json_decode($request->getContent(), true) ?? [];
        $settings = $this->getOrCreate();

        // ── Expédition ────────────────────────────────────────────────────
        if (array_key_exists('dispatchMinDays', $data)) {
            $settings->setDispatchMinDays($data['dispatchMinDays'] !== null ? (int) $data['dispatchMinDays'] : null);
        }
        if (array_key_exists('dispatchMaxDays', $data)) {
            $settings->setDispatchMaxDays($data['dispatchMaxDays'] !== null ? (int) $data['dispatchMaxDays'] : null);
        }
        if (isset($data['dispatchDaysUnit']) && in_array($data['dispatchDaysUnit'], ['working_days', 'calendar_days'], true)) {
            $settings->setDispatchDaysUnit($data['dispatchDaysUnit']);
        }
        if (array_key_exists('dispatchNote', $data)) {
            $settings->setDispatchNote($data['dispatchNote'] !== '' ? $data['dispatchNote'] : null);
        }

        // ── Livraison gratuite ──────────────────────────────────────────
        if (array_key_exists('freeShippingEnabled', $data)) {
            $settings->setFreeShippingEnabled((bool) $data['freeShippingEnabled']);
        }
        if (array_key_exists('freeShippingThreshold', $data)) {
            $settings->setFreeShippingThreshold(
                $data['freeShippingThreshold'] !== null ? (string) $data['freeShippingThreshold'] : null
            );
        }

        // ── Boutique ──────────────────────────────────────────────────────
        if (array_key_exists('shopName', $data)) {
            $settings->setShopName(trim($data['shopName']) ?: null);
        }
        if (array_key_exists('shopEmail', $data)) {
            $settings->setShopEmail(trim($data['shopEmail']) ?: null);
        }
        if (array_key_exists('shopPhone', $data)) {
            $settings->setShopPhone(trim($data['shopPhone']) ?: null);
        }
        if (array_key_exists('shopAddress', $data)) {
            $settings->setShopAddress(trim($data['shopAddress']) ?: null);
        }
        if (array_key_exists('shopHours', $data)) {
            $settings->setShopHours(trim($data['shopHours']) ?: null);
        }

        // ── Réseaux sociaux ───────────────────────────────────────────────
        if (array_key_exists('socialInstagram', $data)) {
            $settings->setSocialInstagram(trim($data['socialInstagram']) ?: null);
        }
        if (array_key_exists('socialTiktok', $data)) {
            $settings->setSocialTiktok(trim($data['socialTiktok']) ?: null);
        }
        if (array_key_exists('instagramFollowers', $data)) {
            $settings->setInstagramFollowers(trim($data['instagramFollowers']) ?: null);
        }

        // ── Contenu homepage ────────────────────────────────────────────
        if (array_key_exists('communityVignettes', $data)) {
            $settings->setCommunityVignettes($data['communityVignettes']);
        }

        $settings->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse($this->serialize($settings));
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
            // Expédition
            'dispatchMinDays'       => $s->getDispatchMinDays(),
            'dispatchMaxDays'       => $s->getDispatchMaxDays(),
            'dispatchDaysUnit'      => $s->getDispatchDaysUnit(),
            'dispatchNote'          => $s->getDispatchNote(),
            'freeShippingEnabled'   => $s->isFreeShippingEnabled(),
            'freeShippingThreshold' => $s->getFreeShippingThreshold() !== null ? (float) $s->getFreeShippingThreshold() : null,
            // Boutique
            'shopName'         => $s->getShopName(),
            'shopEmail'        => $s->getShopEmail(),
            'shopPhone'        => $s->getShopPhone(),
            'shopAddress'      => $s->getShopAddress(),
            'shopHours'        => $s->getShopHours(),
            // Réseaux sociaux
            'socialInstagram'    => $s->getSocialInstagram(),
            'socialTiktok'       => $s->getSocialTiktok(),
            'instagramFollowers' => $s->getInstagramFollowers(),
            // Contenu homepage
            'communityVignettes' => $s->getCommunityVignettes(),
            // Méta
            'updatedAt'        => $s->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
