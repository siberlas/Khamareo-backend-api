<?php

namespace App\Order\Service\Pdf\Generator;

use App\Order\Entity\Order;
use App\Shipping\Entity\Parcel;
use Dompdf\Dompdf;
use Dompdf\Options;
use Psr\Log\LoggerInterface;
use Twig\Environment;

class DeliveryNoteGenerator implements PdfGeneratorInterface
{
    public function __construct(
        private Environment $twig,
        private LoggerInterface $logger,
        private string $projectDir,
    ) {}

    /** Génère le bon de livraison pour toute la commande (tous les colis). */
    public function generate(Order $order): PdfGenerationResult
    {
        try {
            $html     = $this->buildHtml($order, null, $order->getParcels()->toArray());
            $filename = sprintf(
                'bon-livraison-%s-%s.pdf',
                $order->getOrderNumber(),
                (new \DateTimeImmutable())->format('Ymd')
            );

            $this->logger->info('Delivery note generated (order)', [
                'order_id' => $order->getId()->toRfc4122(),
            ]);

            return PdfGenerationResult::success($this->renderPdf($html), $filename);

        } catch (\Throwable $e) {
            $this->logger->error('Delivery note generation failed', [
                'order_id' => $order->getId()->toRfc4122(),
                'error'    => $e->getMessage(),
            ]);

            return PdfGenerationResult::failure($e->getMessage());
        }
    }

    /**
     * Génère le bon de livraison pour un colis spécifique.
     * Appelé après ColissimoLabelGenerator::generateLabelForParcel().
     */
    public function generateForParcel(Parcel $parcel): PdfGenerationResult
    {
        $order = $parcel->getOrder();

        try {
            $html     = $this->buildHtml($order, $parcel, [$parcel]);
            $filename = sprintf(
                'bon-livraison-%s-colis%s-%s.pdf',
                $order->getOrderNumber(),
                $parcel->getParcelNumber() ?? '1',
                (new \DateTimeImmutable())->format('Ymd')
            );

            $this->logger->info('Delivery note generated (parcel)', [
                'order_id'  => $order->getId()->toRfc4122(),
                'parcel_id' => $parcel->getId()->toRfc4122(),
            ]);

            return PdfGenerationResult::success($this->renderPdf($html), $filename);

        } catch (\Throwable $e) {
            $this->logger->error('Delivery note (parcel) generation failed', [
                'order_id'  => $order->getId()->toRfc4122(),
                'parcel_id' => $parcel->getId()->toRfc4122(),
                'error'     => $e->getMessage(),
            ]);

            return PdfGenerationResult::failure($e->getMessage());
        }
    }

    public function getType(): string
    {
        return 'delivery_note';
    }

    private function buildHtml(Order $order, ?Parcel $parcel, array $parcels): string
    {
        return $this->twig->render('document/bon_livraison.html.twig', [
            'order'       => $order,
            'parcel'      => $parcel,
            'parcels'     => $parcels,
            'generatedAt' => new \DateTimeImmutable(),
            'logoPath'    => $this->projectDir . '/public/images/logo.png',
        ]);
    }

    private function renderPdf(string $html): string
    {
        $options = new Options();
        $options->set('defaultFont', 'Helvetica');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}