<?php

namespace App\Shared\Service;

use App\Shared\Repository\AppSettingsRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MailchimpService
{
    public function __construct(
        private readonly HttpClientInterface   $httpClient,
        private readonly LoggerInterface       $logger,
        private readonly AppSettingsRepository $settingsRepo,
        private readonly string                $envApiKey,
        private readonly string                $envListId,
        private readonly string                $envServerPrefix,
    ) {}

    // -------------------------------------------------------------------------
    // Config resolution (DB > env)
    // -------------------------------------------------------------------------

    /** @return array{apiKey: string, listId: string, server: string} */
    private function resolveConfig(): array
    {
        $dbApiKey = $this->settingsRepo->findByKey('mailchimp_api_key')?->getSettingValue() ?? '';
        $dbListId = $this->settingsRepo->findByKey('mailchimp_list_id')?->getSettingValue() ?? '';
        $dbServer = $this->settingsRepo->findByKey('mailchimp_server_prefix')?->getSettingValue() ?? '';

        return [
            'apiKey' => $dbApiKey !== '' ? $dbApiKey : $this->envApiKey,
            'listId' => $dbListId !== '' ? $dbListId : $this->envListId,
            'server' => $dbServer !== '' ? $dbServer : $this->envServerPrefix,
        ];
    }

    private function baseUrl(string $server): string
    {
        return "https://{$server}.api.mailchimp.com/3.0";
    }

    // -------------------------------------------------------------------------
    // Public API — config
    // -------------------------------------------------------------------------

    public function isConfigured(): bool
    {
        $c = $this->resolveConfig();
        return $c['apiKey'] !== '' && $c['listId'] !== '' && $c['server'] !== '';
    }

    /** @return array{configured: bool, api_key_configured: bool, list_id: string|null, server_prefix: string|null} */
    public function getConfigStatus(): array
    {
        $c = $this->resolveConfig();
        return [
            'configured'         => $this->isConfigured(),
            'api_key_configured' => $c['apiKey'] !== '',
            'list_id'            => $c['listId'] ?: null,
            'server_prefix'      => $c['server'] ?: null,
        ];
    }

    // -------------------------------------------------------------------------
    // Public API — diagnostic
    // -------------------------------------------------------------------------

    /**
     * Teste la connexion Mailchimp et retourne des infos de diagnostic.
     * N'est jamais silencieux : retourne toujours les erreurs brutes.
     *
     * @return array{
     *   configured: bool,
     *   server: string|null,
     *   list_id: string|null,
     *   api_key_set: bool,
     *   audiences_beta: array{success: bool, count: int, total: int|null, error: string|null},
     *   lists_api: array{success: bool, count: int, error: string|null}
     * }
     */
    public function testConnection(): array
    {
        $c = $this->resolveConfig();

        $result = [
            'configured'  => $this->isConfigured(),
            'server'      => $c['server'] ?: null,
            'list_id'     => $c['listId'] ?: null,
            'api_key_set' => $c['apiKey'] !== '',
            'audiences_beta' => ['success' => false, 'count' => 0, 'total' => null, 'error' => 'Non testé (config manquante)'],
            'lists_api'      => ['success' => false, 'count' => 0, 'error' => 'Non testé (config manquante)'],
        ];

        if (!$this->isConfigured()) {
            return $result;
        }

        // Test Audiences BETA endpoint
        try {
            $response = $this->httpClient->request(
                'GET',
                "{$this->baseUrl($c['server'])}/audiences/{$c['listId']}/contacts",
                [
                    'auth_basic' => ['anystring', $c['apiKey']],
                    'query'      => ['count' => 10],
                ]
            );
            $data = $response->toArray();
            $result['audiences_beta'] = [
                'success' => true,
                'count'   => count($data['contacts'] ?? []),
                'total'   => $data['total_contacts'] ?? null,
                'error'   => null,
            ];
        } catch (\Throwable $e) {
            $result['audiences_beta'] = ['success' => false, 'count' => 0, 'total' => null, 'error' => $e->getMessage()];
        }

        // Test Lists API (classic)
        try {
            $response = $this->httpClient->request(
                'GET',
                "{$this->baseUrl($c['server'])}/lists/{$c['listId']}/members",
                [
                    'auth_basic' => ['anystring', $c['apiKey']],
                    'query'      => ['count' => 1, 'offset' => 0],
                ]
            );
            $data = $response->toArray();
            $result['lists_api'] = [
                'success' => true,
                'count'   => (int) ($data['total_items'] ?? 0),
                'error'   => null,
            ];
        } catch (\Throwable $e) {
            $result['lists_api'] = ['success' => false, 'count' => 0, 'error' => $e->getMessage()];
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Public API — membres
    // -------------------------------------------------------------------------

    /**
     * Ajoute ou met à jour un membre dans la liste Mailchimp (upsert).
     * Utilise l'API Lists classique. Silencieux en cas d'erreur.
     */
    public function addMember(string $email): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $c         = $this->resolveConfig();
        $emailHash = md5(strtolower(trim($email)));

        try {
            $this->httpClient->request('PUT', "{$this->baseUrl($c['server'])}/lists/{$c['listId']}/members/{$emailHash}", [
                'auth_basic' => ['anystring', $c['apiKey']],
                'headers'    => ['Content-Type' => 'application/json'],
                'json'       => [
                    'email_address' => strtolower(trim($email)),
                    'status_if_new' => 'subscribed',
                ],
            ])->getContent();
        } catch (\Throwable $e) {
            $this->logger->warning('Mailchimp addMember failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Retourne tous les contacts de l'audience via l'API Audiences BETA.
     * Inclut tous les statuts (subscribed, pending, unsubscribed…) et les
     * données enrichies : prénom, nom, source, consentement, dates.
     * Utilisé pour l'affichage admin.
     *
     * Endpoint : GET /audiences/{audience_id}/contacts
     * Pagination par cursor (pas d'offset).
     *
     * @return array<array{
     *   email: string,
     *   status: string,
     *   first_name: string|null,
     *   last_name: string|null,
     *   language: string|null,
     *   marketing_consent: string|null,
     *   source_name: string|null,
     *   created_at: string|null,
     *   last_updated_at: string|null
     * }>
     */
    public function getAudienceMembers(): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $c       = $this->resolveConfig();
        $members = [];
        $cursor  = null;

        try {
            do {
                $query = ['count' => 1000];
                if ($cursor !== null) {
                    $query['cursor'] = $cursor;
                }

                $response = $this->httpClient->request(
                    'GET',
                    "{$this->baseUrl($c['server'])}/audiences/{$c['listId']}/contacts",
                    [
                        'auth_basic' => ['anystring', $c['apiKey']],
                        'query'      => $query,
                    ]
                );

                $data   = $response->toArray();
                $cursor = ($data['next_cursor'] ?? '') ?: null;

                foreach ($data['contacts'] ?? [] as $contact) {
                    $email = strtolower(trim($contact['email_channel']['email'] ?? ''));
                    if ($email === '') {
                        continue;
                    }

                    $members[] = [
                        'email'              => $email,
                        'status'             => $contact['email_channel']['effective_subscription_status']['value'] ?? 'unknown',
                        'first_name'         => trim($contact['merge_fields']['FNAME'] ?? '') ?: null,
                        'last_name'          => trim($contact['merge_fields']['LNAME'] ?? '') ?: null,
                        'language'           => $contact['language'] ?? null,
                        'marketing_consent'  => $contact['email_channel']['marketing_consent']['status'] ?? null,
                        'source_name'        => $contact['email_channel']['source']['name'] ?? ($contact['source']['name'] ?? null),
                        'created_at'         => $contact['created_at'] ?? null,
                        'last_updated_at'    => $contact['last_updated_at'] ?? null,
                    ];
                }
            } while ($cursor !== null);
        } catch (\Throwable $e) {
            $this->logger->error('Mailchimp getAudienceMembers (Audiences BETA) failed', [
                'error' => $e->getMessage(),
            ]);

            // Fallback vers l'API Lists classique si l'endpoint BETA échoue
            $this->logger->info('Mailchimp: fallback to Lists API');
            return $this->fetchMembersViaListsApi();
        }

        return $members;
    }

    /**
     * Retourne uniquement les emails des membres avec statut "subscribed".
     * Utilise l'API Lists classique — plus fiable pour le filtrage par statut.
     * Utilisé pour les envois d'emails (respect des désinscriptions).
     *
     * @return string[]
     */
    public function getSubscribedEmails(): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $members = $this->fetchMembersViaListsApi('subscribed');
        return array_column($members, 'email');
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * API Lists classique (v3) — GET /lists/{listId}/members
     * Pagination par offset. Utilisé pour l'envoi d'emails et en fallback.
     *
     * @return array<array{email: string, status: string}>
     */
    private function fetchMembersViaListsApi(string $status = ''): array
    {
        $c       = $this->resolveConfig();
        $members = [];
        $count   = 1000;
        $offset  = 0;

        try {
            do {
                $query = [
                    'count'  => $count,
                    'offset' => $offset,
                ];

                if ($status !== '') {
                    $query['status'] = $status;
                }

                $response = $this->httpClient->request(
                    'GET',
                    "{$this->baseUrl($c['server'])}/lists/{$c['listId']}/members",
                    [
                        'auth_basic' => ['anystring', $c['apiKey']],
                        'query'      => $query,
                    ]
                );

                $data  = $response->toArray();
                $total = (int) ($data['total_items'] ?? 0);

                foreach ($data['members'] ?? [] as $member) {
                    $email = strtolower(trim($member['email_address'] ?? ''));
                    if ($email !== '') {
                        $members[] = [
                            'email'  => $email,
                            'status' => $member['status'] ?? 'unknown',
                        ];
                    }
                }

                $offset += $count;
            } while ($offset < $total);
        } catch (\Throwable $e) {
            $this->logger->error('Mailchimp fetchMembersViaListsApi failed', [
                'status_filter' => $status ?: 'none',
                'error'         => $e->getMessage(),
            ]);
        }

        return $members;
    }
}
