<?php

namespace App\Admin\Controller\Order;

use App\Order\Entity\Order;
use App\Order\Repository\DigitalDownloadRepository;
use App\Shared\Entity\StoreSettings;
use App\Shared\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Suivi et renvoi des liens de téléchargement d'une commande (livres numériques).
 */
#[AsController]
#[Route('/api/admin/orders/{orderId}/downloads', name: 'admin_order_downloads_')]
class OrderDigitalDownloadController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DigitalDownloadRepository $downloads,
        private readonly MailerService $mailer,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(string $orderId): JsonResponse
    {
        $order = $this->em->getRepository(Order::class)->find($orderId);
        if (!$order instanceof Order) {
            return $this->json(['error' => 'Commande introuvable'], 404);
        }

        $rows = array_map(fn ($d) => [
            'id' => (string) $d->getId(),
            'productName' => $d->getProduct()?->getName(),
            'email' => $d->getEmail(),
            'createdAt' => $d->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'expiresAt' => $d->getExpiresAt()?->format(\DateTimeInterface::ATOM),
            'maxDownloads' => $d->getMaxDownloads(),
            'downloadCount' => $d->getDownloadCount(),
            'firstDownloadedAt' => $d->getFirstDownloadedAt()?->format(\DateTimeInterface::ATOM),
            'lastDownloadedAt' => $d->getLastDownloadedAt()?->format(\DateTimeInterface::ATOM),
            'revokedAt' => $d->getRevokedAt()?->format(\DateTimeInterface::ATOM),
            'downloadable' => $d->isDownloadable(),
        ], $this->downloads->findByOrder($order));

        return $this->json(['downloads' => $rows]);
    }

    #[Route('/{id}/resend', name: 'resend', methods: ['POST'])]
    public function resend(string $orderId, string $id): JsonResponse
    {
        $download = $this->downloads->find($id);
        if ($download === null || (string) $download->getCustomerOrder()->getId() !== $orderId) {
            return $this->json(['error' => 'Lien de téléchargement introuvable'], 404);
        }

        $settings = $this->em->getRepository(StoreSettings::class)->findOneBy([]);
        $validityDays = $settings?->getEbookDownloadValidityDays();
        $expiresAt = $validityDays !== null
            ? (new \DateTimeImmutable())->modify("+{$validityDays} days")
            : null;

        $download->reissue($expiresAt);
        $this->em->flush();

        $this->mailer->sendEbookDelivery($download);

        return $this->json([
            'success' => true,
            'expiresAt' => $download->getExpiresAt()?->format(\DateTimeInterface::ATOM),
            'downloadCount' => $download->getDownloadCount(),
        ]);
    }
}
