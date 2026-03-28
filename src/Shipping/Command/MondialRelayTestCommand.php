<?php

namespace App\Shipping\Command;

use App\Shipping\DTO\MondialRelay\MondialRelayAddressDTO;
use App\Shipping\DTO\MondialRelay\MondialRelayShipmentDTO;
use App\Shipping\Service\MondialRelayApiService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:mondial-relay:test',
    description: 'Test Mondial Relay label generation with sandbox API',
)]
class MondialRelayTestCommand extends Command
{
    public function __construct(
        private MondialRelayApiService $mondialRelayApi,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Mondial Relay - Test Shipment Creation');

        $sender = new MondialRelayAddressDTO(
            title: 'MR',
            firstname: 'Khamareo',
            lastname: 'Shop',
            addressAdd1: 'KHAMAREO SHOP',
            streetname: '1 RUE EXEMPLE',
            postcode: '75001',
            city: 'PARIS',
            countryCode: 'FR',
            phoneNo: '0600000000',
            email: 'noreply@khamareo.com',
        );

        $recipient = new MondialRelayAddressDTO(
            title: 'MME',
            firstname: 'Marie',
            lastname: 'Dupont',
            addressAdd1: 'MARIE DUPONT',
            streetname: '10 RUE DE LA PAIX',
            postcode: '75002',
            city: 'PARIS',
            countryCode: 'FR',
            phoneNo: '0612345678',
            mobileNo: '0612345678',
            email: 'marie.dupont@example.com',
        );

        $shipment = new MondialRelayShipmentDTO(
            sender: $sender,
            recipient: $recipient,
            weightGrams: 500,
            deliveryMode: '24R',
            deliveryLocation: 'FR-66974',
            collectionMode: 'CCC',
            orderNo: 'TEST-' . date('His'),
            parcelContent: 'Produits naturels',
        );

        $io->section('Sending test shipment to Mondial Relay sandbox...');

        try {
            $result = $this->mondialRelayApi->createLabel($shipment);

            $io->success('Label created successfully!');
            $io->table(
                ['Field', 'Value'],
                [
                    ['Shipment Number', $result->shipmentNumber],
                    ['Label URL', $result->labelUrl],
                ]
            );

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Failed: ' . $e->getMessage());

            if (method_exists($e, 'getErrorCodes')) {
                $io->listing($e->getErrorCodes());
            }

            return Command::FAILURE;
        }
    }
}
