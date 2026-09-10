<?php

namespace App\Order\Controller;

use App\Media\Service\CloudinaryService;
use App\Order\Repository\DigitalDownloadRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Téléchargement public d'un livre numérique par token (64 hexa, imprévisible).
 * Le fichier réel est sur Cloudinary (raw/authenticated) : on redirige (302)
 * vers une URL signée à durée de vie courte, jamais l'URL en clair.
 */
#[AsController]
class DigitalDownloadController extends AbstractController
{
    public function __construct(
        private readonly DigitalDownloadRepository $downloads,
        private readonly CloudinaryService $cloudinary,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Statut du lien — NE CONSOMME PAS le téléchargement. Utilisé par la page
     * front pour afficher l'état avant que l'utilisateur ne clique (les clients
     * mail / scanners de sécurité pré-chargent les URLs : un GET ne doit pas
     * brûler le lien à usage unique).
     */
    #[Route('/api/public/downloads/{token}/status', name: 'public_ebook_download_status', methods: ['GET'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function status(string $token): JsonResponse
    {
        $download = $this->downloads->findOneByToken($token);
        if ($download === null) {
            return $this->json(['valid' => false, 'reason' => 'unknown'], 404);
        }

        if ($download->isDownloadable()) {
            return $this->json([
                'valid' => true,
                'productName' => $download->getProduct()?->getName(),
                'expiresAt' => $download->getExpiresAt()?->format(\DateTimeInterface::ATOM),
            ]);
        }

        return $this->json([
            'valid' => false,
            'reason' => $this->reason($download),
        ], 200);
    }

    #[Route('/api/public/downloads/{token}', name: 'public_ebook_download', methods: ['GET'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function __invoke(string $token): RedirectResponse|JsonResponse
    {
        $download = $this->downloads->findOneByToken($token);
        if ($download === null) {
            return $this->json(['error' => 'Lien de téléchargement inconnu.', 'reason' => 'unknown'], 404);
        }

        if (!$download->isDownloadable()) {
            return $this->json([
                'error' => 'Ce lien de téléchargement n\'est plus valide.',
                'reason' => $this->reason($download),
            ], 410);
        }

        // URL signée générée AVANT de consommer : si Cloudinary échoue, le token
        // n'est pas brûlé.
        try {
            $url = $this->cloudinary->signedRawUrl($download->getDeliveredFilePublicId(), ttlSeconds: 600);
        } catch (\Throwable $e) {
            $this->logger->error('Ebook download: URL signée Cloudinary indisponible', [
                'download_id' => (string) $download->getId(),
                'error' => $e->getMessage(),
            ]);
            return $this->json(['error' => 'Téléchargement momentanément indisponible, réessayez.'], 503);
        }

        $download->registerDownload();
        $this->em->flush();

        $this->logger->info('Ebook download served', [
            'download_id' => (string) $download->getId(),
            'order' => $download->getCustomerOrder()->getOrderNumber(),
        ]);

        return new RedirectResponse($url, 302);
    }

    private function reason(\App\Order\Entity\DigitalDownload $d): string
    {
        return $d->getRevokedAt() !== null ? 'revoked'
            : ($d->isExpired() ? 'expired' : 'consumed');
    }
}
