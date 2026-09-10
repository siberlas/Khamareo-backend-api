<?php

namespace App\Order\Service\Digital;

use App\Catalog\Enum\ProductType;
use App\Media\Service\CloudinaryService;
use App\Order\Entity\DigitalDownload;
use App\Order\Entity\Order;
use App\Order\Entity\OrderItem;
use App\Order\Repository\DigitalDownloadRepository;
use App\Shared\Entity\StoreSettings;
use App\Shared\Enum\OrderStatus;
use App\Shared\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Livraison des livres numériques d'une commande payée :
 * pour chaque ligne numérique, génère le PDF watermarké, le stocke sur
 * Cloudinary (raw/authenticated), crée un DigitalDownload et envoie l'email.
 *
 * Idempotent : rejouable sans effet de bord (webhook Stripe renvoyé).
 */
class DigitalDeliveryService
{
    private const DELIVERED_FOLDER = 'khamareo/ebooks/delivered';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DigitalDownloadRepository $downloads,
        private readonly CloudinaryService $cloudinary,
        private readonly PdfWatermarkService $watermark,
        private readonly MailerService $mailer,
        private readonly LoggerInterface $logger,
    ) {}

    public function fulfill(Order $order): void
    {
        $settings = $this->em->getRepository(StoreSettings::class)->findOneBy([]);
        $validityDays = $settings?->getEbookDownloadValidityDays();
        $expiresAt = $validityDays !== null
            ? (new \DateTimeImmutable())->modify("+{$validityDays} days")
            : null;

        $buyerEmail = $order->getOwner()?->getEmail() ?? $order->getGuestEmail();

        if (!$buyerEmail) {
            $this->logger->error('Livraison ebook impossible : email acheteur introuvable', [
                'order' => $order->getOrderNumber(),
            ]);
            return;
        }

        $allDelivered = true;

        foreach ($order->getItems() as $item) {
            if ($item->getProductType() !== ProductType::DIGITAL) {
                continue;
            }

            $existing = $this->downloads->findOneByOrderItem($item);
            if ($existing !== null) {
                // Déjà traité (rejoué par un webhook) : livré si l'email est parti.
                $allDelivered = $allDelivered && $existing->getEmailSentAt() !== null;
                continue;
            }

            try {
                $download = $this->createDownload($order, $item, $buyerEmail, $expiresAt);
                $this->em->persist($download);
                $this->em->flush();

                $sent = $this->mailer->sendEbookDelivery($download);
                $allDelivered = $allDelivered && $sent;
            } catch (\Throwable $e) {
                $allDelivered = false;
                $this->logger->error('Échec livraison ebook pour une ligne de commande', [
                    'order' => $order->getOrderNumber(),
                    'order_item' => (string) $item->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Commande 100 % numérique : une fois tous les liens envoyés, elle est
        // « expédiée » (livrée par email — aucun colis).
        if ($allDelivered
            && $order->getStatus() === OrderStatus::PAID
            && !$order->hasPhysicalItems()
        ) {
            $order->setStatus(OrderStatus::SHIPPED)->setShippedAt(new \DateTimeImmutable());
            $this->em->flush();

            $this->logger->info('Commande 100% numérique marquée expédiée', [
                'order' => $order->getOrderNumber(),
            ]);
        }
    }

    private function createDownload(
        Order $order,
        OrderItem $item,
        string $buyerEmail,
        ?\DateTimeImmutable $expiresAt,
    ): DigitalDownload {
        $product = $item->getProduct();
        $sourcePublicId = $product?->getDigitalFilePublicId();
        if ($product === null || $sourcePublicId === null) {
            throw new \RuntimeException('Produit numérique sans fichier source');
        }

        $sourcePdf = $this->cloudinary->downloadRawByPublicId($sourcePublicId);
        if ($sourcePdf === false) {
            throw new \RuntimeException('PDF source illisible sur Cloudinary : ' . $sourcePublicId);
        }

        $footer = sprintf(
            'Exemplaire nominatif — %s — commande %s',
            $buyerEmail,
            $order->getOrderNumber()
        );

        try {
            $deliveredPdf = $this->watermark->watermark($sourcePdf, $footer);
        } catch (\RuntimeException $e) {
            // PDF non watermarkable (version récente / chiffré) : on livre l'original.
            $this->logger->warning('Ebook livré sans watermark', [
                'order' => $order->getOrderNumber(),
                'product' => (string) $product->getId(),
                'reason' => $e->getMessage(),
            ]);
            $deliveredPdf = $sourcePdf;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ebook_out_') . '.pdf';
        file_put_contents($tmp, $deliveredPdf);
        try {
            $upload = $this->cloudinary->uploadRawAuthenticated($tmp, self::DELIVERED_FOLDER);
        } finally {
            @unlink($tmp);
        }
        if (!($upload['success'] ?? false)) {
            throw new \RuntimeException('Upload du PDF livré échoué : ' . ($upload['error'] ?? '?'));
        }

        $download = (new DigitalDownload())
            ->setOrderItem($item)
            ->setCustomerOrder($order)
            ->setProduct($product)
            ->setEmail($buyerEmail)
            ->setMaxDownloads(1)
            ->setExpiresAt($expiresAt)
            ->setDeliveredFilePublicId($upload['publicId']);

        return $download;
    }
}
