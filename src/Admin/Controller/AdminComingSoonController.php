<?php

namespace App\Admin\Controller;

use App\Shared\Entity\AppSettings;
use App\Shared\Repository\AppSettingsRepository;
use App\Shared\Repository\PreRegistrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
class AdminComingSoonController
{
    public function __construct(
        private readonly EntityManagerInterface    $em,
        private readonly AppSettingsRepository     $settingsRepo,
        private readonly PreRegistrationRepository $preRegRepo,
    ) {}

    /**
     * GET /waraba-l19/settings/coming-soon
     * Retourne les paramètres actuels du mode coming soon.
     */
    #[Route('/api/admin/coming-soon/settings', name: 'admin_coming_soon_get', methods: ['GET'])]
    public function getSettings(): JsonResponse
    {
        $enabledSetting    = $this->settingsRepo->findByKey('coming_soon_enabled');
        $launchDateSetting = $this->settingsRepo->findByKey('coming_soon_launch_date');

        return new JsonResponse([
            'enabled'     => $enabledSetting?->getSettingValue() === 'true',
            'launch_date' => $launchDateSetting?->getSettingValue() ?: null,
        ]);
    }

    /**
     * PUT /waraba-l19/settings/coming-soon
     * Met à jour les paramètres du mode coming soon.
     * Body: { enabled: bool, launch_date: string|null }
     */
    #[Route('/api/admin/coming-soon/settings', name: 'admin_coming_soon_update', methods: ['PUT'])]
    public function updateSettings(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('enabled', $data)) {
            $this->upsertSetting('coming_soon_enabled', $data['enabled'] ? 'true' : 'false');
        }

        if (array_key_exists('launch_date', $data)) {
            $launchDate = ($data['launch_date'] !== null && $data['launch_date'] !== '')
                ? (string) $data['launch_date']
                : null;
            $this->upsertSetting('coming_soon_launch_date', $launchDate);
        }

        $this->em->flush();

        return $this->getSettings();
    }

    /**
     * GET /waraba-l19/pre-registrations
     * Liste paginée des pré-inscriptions.
     */
    #[Route('/api/admin/coming-soon/pre-registrations', name: 'admin_pre_registrations_list', methods: ['GET'])]
    public function listPreRegistrations(Request $request): JsonResponse
    {
        $page  = max(1, (int) $request->query->get('page', 1));
        $limit = min(200, max(1, (int) $request->query->get('limit', 50)));

        $items = $this->preRegRepo->findAllOrderedByDate($page, $limit);
        $total = $this->preRegRepo->countAll();

        return new JsonResponse([
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
            'items' => array_map(fn($p) => [
                'id'           => (string) $p->getId(),
                'email'        => $p->getEmail(),
                'consentGiven' => $p->isConsentGiven(),
                'ipAddress'    => $p->getIpAddress(),
                'createdAt'    => $p->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ], $items),
        ]);
    }

    /**
     * GET /waraba-l19/pre-registrations/export
     * Export CSV de toutes les pré-inscriptions.
     */
    #[Route('/api/admin/coming-soon/pre-registrations/export', name: 'admin_pre_registrations_export', methods: ['GET'])]
    public function exportPreRegistrations(): StreamedResponse
    {
        $items = $this->preRegRepo->findAllOrderedByDate(1, 10000);

        $response = new StreamedResponse(function () use ($items) {
            $handle = fopen('php://output', 'w');

            // BOM UTF-8 pour Excel
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['ID', 'Email', 'Consentement', 'IP', 'Date inscription'], ';');

            foreach ($items as $p) {
                fputcsv($handle, [
                    (string) $p->getId(),
                    $p->getEmail(),
                    $p->isConsentGiven() ? 'oui' : 'non',
                    $p->getIpAddress() ?? '',
                    $p->getCreatedAt()->format('d/m/Y H:i:s'),
                ], ';');
            }

            fclose($handle);
        });

        $filename = 'pre-inscriptions-' . date('Y-m-d') . '.csv';
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    // -------------------------------------------------------------------------

    private function upsertSetting(string $key, ?string $value): void
    {
        $setting = $this->settingsRepo->findByKey($key);

        if ($setting) {
            $setting->setSettingValue($value);
        } else {
            $setting = new AppSettings($key, $value);
            $this->em->persist($setting);
        }
    }
}
