<?php

namespace App\Order\Service\Pdf\Generator;

use App\Order\Entity\Order;
use Dompdf\Dompdf;
use Dompdf\Options;
use Psr\Log\LoggerInterface;
use Twig\Environment;

class PurchaseOrderGenerator implements PdfGeneratorInterface
{
    public function __construct(
        private Environment $twig,
        private LoggerInterface $logger,
        private string $projectDir,
        private string $sellerSiren = '',
        private string $sellerSiret = '',
        private string $sellerAddress = '',
        private string $sellerLegalForm = 'Micro-entrepreneur',
        private string $sellerVatNumber = '',
        private string $sellerTvaMention = 'TVA non applicable, article 293 B du CGI',
    ) {}

    public function generate(Order $order): PdfGenerationResult
    {
        try {
            $html = $this->twig->render('document/bon_commande.html.twig', [
                'order'           => $order,
                'generatedAt'     => new \DateTimeImmutable(),
                'logoPath'        => $this->projectDir . '/public/images/logo.png',
                'sellerSiren'     => $this->sellerSiren,
                'sellerSiret'     => $this->sellerSiret,
                'sellerAddress'   => $this->sellerAddress,
                'sellerLegalForm' => $this->sellerLegalForm,
                'sellerVatNumber' => $this->sellerVatNumber,
                'tvaMention'      => $this->sellerTvaMention,
            ]);

            $filename = sprintf(
                'bon-commande-%s-%s.pdf',
                $order->getOrderNumber(),
                (new \DateTimeImmutable())->format('Ymd')
            );

            $this->logger->info('Purchase order generated', [
                'order_id' => $order->getId()->toRfc4122(),
                'filename' => $filename,
            ]);

            return PdfGenerationResult::success($this->renderPdf($html), $filename);

        } catch (\Throwable $e) {
            $this->logger->error('Purchase order generation failed', [
                'order_id' => $order->getId()->toRfc4122(),
                'error'    => $e->getMessage(),
            ]);

            return PdfGenerationResult::failure($e->getMessage());
        }
    }

    public function getType(): string
    {
        return 'purchase_order';
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