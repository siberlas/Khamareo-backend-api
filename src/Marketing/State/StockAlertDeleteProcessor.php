<?php

namespace App\Marketing\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Marketing\Entity\StockAlert;
use App\Shared\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class StockAlertDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private MailerService $mailerService,
        private LoggerInterface $logger
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof StockAlert) {
            return $data;
        }

        try {
            $this->mailerService->sendStockAlertDeactivated($data);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send stock alert deactivation email', [
                'alert_id' => $data->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        $this->em->remove($data);
        $this->em->flush();

        return null;
    }
}
