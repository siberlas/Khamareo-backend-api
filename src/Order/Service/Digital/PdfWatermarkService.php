<?php

namespace App\Order\Service\Digital;

use Psr\Log\LoggerInterface;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Ajoute un pied de page nominatif (« Exemplaire de … ») sur chaque page d'un
 * PDF, pour dissuader le partage. Basé sur FPDI (import) + TCPDF (rendu).
 *
 * FPDI (version libre) ne sait importer que les PDF ≤ 1.4. Pour un PDF plus
 * récent ou chiffré, watermark() lève une exception : l'appelant doit alors
 * livrer le fichier original tel quel (cf. DigitalDeliveryService).
 */
class PdfWatermarkService
{
    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * @return string Le contenu binaire du PDF watermarké.
     * @throws \RuntimeException si le PDF ne peut pas être importé/traité.
     */
    public function watermark(string $pdfContent, string $footerText): string
    {
        $tmpIn = tempnam(sys_get_temp_dir(), 'ebook_src_') . '.pdf';
        file_put_contents($tmpIn, $pdfContent);

        try {
            $pdf = new Fpdi();
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetAutoPageBreak(false);
            $pdf->SetMargins(0, 0, 0);

            $pageCount = $pdf->setSourceFile($tmpIn);

            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $tpl = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($tpl);
                $orientation = ($size['orientation'] ?? ($size['width'] > $size['height'] ? 'L' : 'P'));

                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl, 0, 0, $size['width'], $size['height'], true);

                // Tampon nominatif dans la marge extérieure droite, texte tourné
                // à 90° (lecture de bas en haut). Évite toute superposition avec
                // le pied de page ou la bordure décorative du PDF d'origine.
                $x = $size['width'] - 5;
                $y = $size['height'] - 10;

                $pdf->SetFont('helvetica', '', 6);
                $pdf->SetTextColor(150, 150, 150);
                $pdf->StartTransform();
                $pdf->Rotate(90, $x, $y);
                $pdf->Text($x, $y, $footerText);
                $pdf->StopTransform();
            }

            return $pdf->Output('ebook.pdf', 'S');
        } catch (\Throwable $e) {
            throw new \RuntimeException('Watermark impossible : ' . $e->getMessage(), 0, $e);
        } finally {
            @unlink($tmpIn);
        }
    }
}
