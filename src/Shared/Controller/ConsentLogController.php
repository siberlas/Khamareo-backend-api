<?php

namespace App\Shared\Controller;
use Symfony\Component\HttpKernel\Attribute\AsController;

use App\Shared\Entity\ConsentLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Endpoint public pour journaliser les consentements RGPD.
 * POST /api/public/consent
 *
 * Body JSON :
 * {
 *   "type":       "cookies" | "marketing_opt_in" | "cgv",
 *   "choice":     "accepted" | "rejected" | "granted" | "denied",
 *   "version":    "1.0",           (optionnel)
 *   "guestToken": "...",           (optionnel)
 * }
 */
#[Route('/api/public/consent', name: 'public_consent_log', methods: ['POST'])]
#[AsController]
class ConsentLogController extends AbstractController
{
    private const ALLOWED_TYPES   = ['cookies', 'marketing_opt_in', 'cgv'];
    private const ALLOWED_CHOICES = ['accepted', 'rejected', 'granted', 'denied'];

    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private RateLimiterFactory $consentLimiter,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $limiter = $this->consentLimiter->create($request->getClientIp() ?? 'unknown');
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop de requêtes.'], 429);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $type   = $data['type']   ?? null;
        $choice = $data['choice'] ?? null;

        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            return $this->json(['error' => 'Type invalide.'], 400);
        }

        if (!in_array($choice, self::ALLOWED_CHOICES, true)) {
            return $this->json(['error' => 'Choix invalide.'], 400);
        }

        $log = new ConsentLog();
        $log->setType($type)
            ->setChoice($choice)
            ->setVersion($data['version'] ?? null)
            ->setUserAgent($request->headers->get('User-Agent'));

        // Pseudonymisation IP : on ne conserve que les 3 premiers octets pour IPv4
        $ip = $request->getClientIp();
        if ($ip) {
            $log->setIpAddress($this->pseudonymizeIp($ip));
        }

        // Utilisateur connecté
        $user = $this->security->getUser();
        if ($user && method_exists($user, 'getId')) {
            $userId = $user->getId();
            $log->setUserId($userId instanceof Uuid ? $userId : Uuid::fromString((string) $userId));
        } elseif (!empty($data['guestToken'])) {
            // Invité : stocker le token (sans lier à une personne)
            $log->setGuestToken(substr($data['guestToken'], 0, 64));
        }

        $this->em->persist($log);
        $this->em->flush();

        return $this->json(['success' => true], 201);
    }

    private function pseudonymizeIp(string $ip): string
    {
        // IPv4 → masquer le dernier octet : 192.168.1.42 → 192.168.1.0
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0';
            return implode('.', $parts);
        }

        // IPv6 → masquer les 80 derniers bits (garder seulement /48)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if ($packed !== false) {
                $masked = substr($packed, 0, 6) . str_repeat("\x00", 10);
                return inet_ntop($masked) ?: $ip;
            }
        }

        return $ip;
    }
}
