<?php

namespace App\Shipping\Service;

use App\Order\Entity\Order;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\Filesystem\Filesystem;

class OrderDocumentPdfService
{
    private string $outputDir;
    private Filesystem $filesystem;

    public function __construct(string $outputDir)
    {
        $this->outputDir = $outputDir;
        $this->filesystem = new Filesystem();
    }

    public function generatePreparationSheet(Order $order): string
    {
        $html = $this->renderPreparationSheetHtml($order);
        return $this->generatePdf($html, 'bon-preparation-' . $order->getOrderNumber() . '.pdf');
    }

    public function generateDeliverySlip(Order $order): string
    {
        $html = $this->renderDeliverySlipHtml($order);
        return $this->generatePdf($html, 'bordereau-livraison-' . $order->getOrderNumber() . '.pdf');
    }

    private function generatePdf(string $html, string $filename): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $this->filesystem->mkdir($this->outputDir);
        $filePath = $this->outputDir . '/' . $filename;
        file_put_contents($filePath, $dompdf->output());
        return $filePath;
    }

    private function renderPreparationSheetHtml(Order $order): string
    {
        // TODO: Adapter le template HTML selon les besoins
        return '<h1>Bon de préparation</h1><p>Commande: ' . $order->getOrderNumber() . '</p>';
    }

    private function renderDeliverySlipHtml(Order $order): string
    {
        // TODO: Adapter le template HTML selon les besoins
        return '<h1>Bordereau de livraison</h1><p>Commande: ' . $order->getOrderNumber() . '</p>';
    }
}
