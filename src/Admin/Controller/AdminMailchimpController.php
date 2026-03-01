<?php

namespace App\Admin\Controller;

use App\Shared\Entity\AppSettings;
use App\Shared\Repository\AppSettingsRepository;
use App\Shared\Repository\PreRegistrationRepository;
use App\Shared\Service\MailchimpService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
class AdminMailchimpController
{
    public function __construct(
        private readonly EntityManagerInterface    $em,
        private readonly AppSettingsRepository     $settingsRepo,
        private readonly PreRegistrationRepository $preRegRepo,
        private readonly MailchimpService          $mailchimpService,
    ) {}

    // -------------------------------------------------------------------------
    // Config Mailchimp
    // -------------------------------------------------------------------------

    /**
     * GET /api/admin/mailchimp/config
     * Retourne le statut de configuration Mailchimp (jamais la clé API complète).
     */
    #[Route('/api/admin/mailchimp/config', name: 'admin_mailchimp_config_get', methods: ['GET'])]
    public function getConfig(): JsonResponse
    {
        return new JsonResponse($this->mailchimpService->getConfigStatus());
    }

    /**
     * PUT /api/admin/mailchimp/config
     * Met à jour la configuration Mailchimp en base (AppSettings).
     * Body: { api_key?: string, list_id: string, server_prefix: string }
     * Si api_key est vide ou absent, la clé existante est conservée.
     */
    #[Route('/api/admin/mailchimp/config', name: 'admin_mailchimp_config_update', methods: ['PUT'])]
    public function updateConfig(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        // Clé API : seulement si non vide (évite d'écraser accidentellement)
        if (!empty($data['api_key'])) {
            $this->upsertSetting('mailchimp_api_key', trim($data['api_key']));
        }

        if (array_key_exists('list_id', $data)) {
            $this->upsertSetting('mailchimp_list_id', trim((string) $data['list_id']) ?: null);
        }

        if (array_key_exists('server_prefix', $data)) {
            $this->upsertSetting('mailchimp_server_prefix', trim((string) $data['server_prefix']) ?: null);
        }

        $this->em->flush();

        return new JsonResponse($this->mailchimpService->getConfigStatus());
    }

    /**
     * GET /api/admin/mailchimp/test
     * Effectue un test de connexion en direct vers l'API Mailchimp
     * et retourne les infos de diagnostic (pas de mise en cache).
     */
    #[Route('/api/admin/mailchimp/test', name: 'admin_mailchimp_test', methods: ['GET'])]
    public function test(): JsonResponse
    {
        return new JsonResponse($this->mailchimpService->testConnection());
    }

    // -------------------------------------------------------------------------
    // Liste fusionnée : site + Mailchimp
    // -------------------------------------------------------------------------

    /**
     * GET /api/admin/mailchimp/subscribers?source=all|site|mailchimp|both
     *
     * Retourne la liste fusionnée des pré-inscrits (site DB + Mailchimp).
     * Source values :
     *  - "site"      → uniquement dans la table pre_registration
     *  - "mailchimp" → uniquement dans la liste Mailchimp
     *  - "both"      → présents dans les deux sources
     *  - "all"       → tous (défaut)
     */
    #[Route('/api/admin/mailchimp/subscribers', name: 'admin_mailchimp_subscribers', methods: ['GET'])]
    public function subscribers(Request $request): JsonResponse
    {
        $sourceFilter = $request->query->get('source', 'all');
        $page         = max(1, (int) $request->query->get('page', 1));
        $limit        = min(200, max(10, (int) $request->query->get('limit', 50)));

        // 1. Pré-inscrits site (tous)
        $siteItems  = $this->preRegRepo->findAllOrderedByDate(1, 10000);
        $siteEmails = [];
        foreach ($siteItems as $item) {
            $siteEmails[strtolower($item->getEmail())] = $item;
        }

        // 2. Membres Mailchimp (TOUS statuts — subscribed, pending, unsubscribed…)
        // Stocke le membre complet (email, status, first_name, last_name, …)
        $mailchimpEmails = [];
        $mailchimpConfigured = $this->mailchimpService->isConfigured();
        if ($mailchimpConfigured) {
            foreach ($this->mailchimpService->getAudienceMembers() as $member) {
                $mailchimpEmails[$member['email']] = $member;
            }
        }

        // 3. Fusion et calcul des sources
        $allEmails = array_unique(array_merge(array_keys($siteEmails), array_keys($mailchimpEmails)));

        $inBoth        = 0;
        $siteOnly      = 0;
        $mailchimpOnly = 0;
        $items         = [];

        foreach ($allEmails as $email) {
            $inSite      = isset($siteEmails[$email]);
            $inMailchimp = isset($mailchimpEmails[$email]);

            if ($inSite && $inMailchimp) {
                $source = 'both';
                ++$inBoth;
            } elseif ($inSite) {
                $source = 'site';
                ++$siteOnly;
            } else {
                $source = 'mailchimp';
                ++$mailchimpOnly;
            }

            // Appliquer le filtre source
            if ($sourceFilter !== 'all' && $source !== $sourceFilter) {
                continue;
            }

            $mc    = $mailchimpEmails[$email] ?? [];
            $entry = [
                'email'                       => $email,
                'source'                      => $source,
                'mailchimp_status'            => $mc['status'] ?? null,
                'mailchimp_first_name'        => $mc['first_name'] ?? null,
                'mailchimp_last_name'         => $mc['last_name'] ?? null,
                'mailchimp_language'          => $mc['language'] ?? null,
                'mailchimp_marketing_consent' => $mc['marketing_consent'] ?? null,
                'mailchimp_source_name'       => $mc['source_name'] ?? null,
                'mailchimp_created_at'        => $mc['created_at'] ?? null,
            ];

            if ($inSite) {
                $preReg = $siteEmails[$email];
                $entry['consentGiven'] = $preReg->isConsentGiven();
                $entry['createdAt']    = $preReg->getCreatedAt()->format(\DateTimeInterface::ATOM);
            }

            $items[] = $entry;
        }

        // Tri : par date décroissante (site en priorité, sinon Mailchimp), puis par email
        usort($items, static function (array $a, array $b): int {
            $dateA = $a['createdAt'] ?? $a['mailchimp_created_at'] ?? null;
            $dateB = $b['createdAt'] ?? $b['mailchimp_created_at'] ?? null;

            if ($dateA === null && $dateB === null) {
                return strcmp($a['email'], $b['email']);
            }
            if ($dateA === null) return 1;  // sans date → en dernier
            if ($dateB === null) return -1;

            $cmp = strcmp($dateB, $dateA); // ISO 8601 → tri lexicographique = chronologique inversé
            return $cmp !== 0 ? $cmp : strcmp($a['email'], $b['email']);
        });

        // Pagination
        $total = count($items);
        $items = array_values(array_slice($items, ($page - 1) * $limit, $limit));

        return new JsonResponse([
            'mailchimp_configured' => $mailchimpConfigured,
            'stats'                => [
                'site_total'           => count($siteEmails),
                'mailchimp_total'      => count($mailchimpEmails),
                'mailchimp_subscribed' => count(array_filter($mailchimpEmails, fn($m) => ($m['status'] ?? '') === 'subscribed')),
                'mailchimp_pending'    => count(array_filter($mailchimpEmails, fn($m) => ($m['status'] ?? '') === 'pending')),
                'both'                 => $inBoth,
                'site_only'            => $siteOnly,
                'mailchimp_only'       => $mailchimpOnly,
                'total_unique'         => count($allEmails),
            ],
            'source_filter' => $sourceFilter,
            'page'          => $page,
            'limit'         => $limit,
            'total'         => $total,
            'count'         => count($items),
            'items'         => $items,
        ]);
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
