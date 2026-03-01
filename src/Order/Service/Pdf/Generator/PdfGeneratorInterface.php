<?php

namespace App\Order\Service\Pdf\Generator;

use App\Order\Entity\Order;

interface PdfGeneratorInterface
{
    public function generate(Order $order): PdfGenerationResult;

    /** Ex: 'purchase_order', 'delivery_note' */
    public function getType(): string;
}