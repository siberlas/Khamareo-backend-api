<?php

namespace App\Shared\Controller;
use Symfony\Component\HttpKernel\Attribute\AsController;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Point de contrôle de santé applicatif.
 * Utilisé par les probes Docker/K8s et les outils de monitoring.
 */
#[AsController]
class HealthController extends AbstractController
{
    public function __construct(
        private Connection $connection,
    ) {}

    #[Route('/api/health', name: 'health_check', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $checks = [];
        $status = 'ok';

        // Database
        try {
            $this->connection->executeQuery('SELECT 1');
            $checks['database'] = 'ok';
        } catch (\Throwable $e) {
            $checks['database'] = 'error';
            $status = 'degraded';
        }

        // Redis (optionnel – non bloquant)
        try {
            $redis = new \Redis();
            $redis->connect('redis', 6379, 1.0);
            $redis->ping();
            $checks['redis'] = 'ok';
            $redis->close();
        } catch (\Throwable) {
            $checks['redis'] = 'unavailable';
            // Redis non bloquant en dev
        }

        $httpCode = $status === 'ok' ? 200 : 503;

        return new JsonResponse([
            'status'    => $status,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'checks'    => $checks,
        ], $httpCode);
    }
}
