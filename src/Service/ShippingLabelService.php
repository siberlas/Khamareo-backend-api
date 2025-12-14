<?php

namespace App\Service;

use App\Entity\Order;
use App\Entity\ShippingLabel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ShippingLabelService
{
    public function __construct(
        private EntityManagerInterface $em,
        private HttpClientInterface $client
    ) {}

    public function generateForOrder(Order $order): ShippingLabel
    {
        // 🚧 Simulation temporaire : pas d'appel à l'API externe
        $label = new ShippingLabel();
        $label->setOrder($order);
        $label->setTrackingNumber('TEST-' . strtoupper(uniqid()));
        $label->setProvider('colissimo');
        $label->setFilePath('upload/');

        // Sauvegarde en base
        $this->em->persist($label);
        $this->em->flush();

        return $label;
    }

    // public function generateForOrder(Order $order): ShippingLabel
    // {
    //     $provider = $order->getShippingMethod()->getName();

    //     // Exemple : envoi vers API Colissimo ou Mondial Relay
    //     $response = $this->client->request('POST', $this->getProviderUrl($provider), [
    //         'json' => [
    //             'orderNumber' => $order->getOrderNumber(),
    //             'recipient' => $order->getShippingAddress()->getFullAddress(),
    //             'weight' => $this->getOrderWeight($order),
    //         ]
    //     ]);

    //     $data = $response->toArray();

    //     // Exemple : retour type { "trackingNumber": "...", "label": "base64PDF" }
    //     $pdfContent = base64_decode($data['label']);
    //     $trackingNumber = $data['trackingNumber'];

    //     $filePath = sprintf('uploads/labels/%s.pdf', $trackingNumber);
    //     file_put_contents($filePath, $pdfContent);

    //     $label = (new ShippingLabel())
    //         ->setProvider($provider)
    //         ->setTrackingNumber($trackingNumber)
    //         ->setFilePath($filePath)
    //         ->setOrder($order);

    //     $this->em->persist($label);
    //     $this->em->flush();

    //     return $label;
    // }

    private function getProviderUrl(string $provider): string
    {
        return match ($provider) {
            'Colissimo' => 'https://api.colissimo.fr/label',
            'Mondial Relay' => 'https://api.mondialrelay.com/label',
            default => throw new \InvalidArgumentException("Provider inconnu : $provider"),
        };
    }

    private function getOrderWeight(Order $order): float
    {
        $total = 0;
        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            if (method_exists($product, 'getWeight')) {
                $total += $product->getWeight() * $item->getQuantity();
            }
        }
        return $total;
    }
}
