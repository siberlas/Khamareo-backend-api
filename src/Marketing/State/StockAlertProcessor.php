<?php

namespace App\Marketing\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Marketing\Entity\StockAlert;
use App\Marketing\Repository\StockAlertRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Psr\Log\LoggerInterface;

final class StockAlertProcessor implements ProcessorInterface
{
    private const MAX_ALERTS_PER_USER = 10;

    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private StockAlertRepository $alertRepository,
        private LoggerInterface $logger
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StockAlert
    {
        assert($data instanceof StockAlert);

        $user = $this->security->getUser();
        
        if (!$user) {
            throw new BadRequestHttpException('Vous devez être connecté pour créer une alerte stock.');
        }

        // Assigner l'utilisateur
        $data->setOwner($user);

        // Vérifier la limite d'alertes
        $activeAlertsCount = $this->alertRepository->countActiveAlerts($user);
        
        if ($activeAlertsCount >= self::MAX_ALERTS_PER_USER) {
            throw new TooManyRequestsHttpException(
                null,
                sprintf('Vous avez atteint la limite de %d alertes actives.', self::MAX_ALERTS_PER_USER)
            );
        }

        // Vérifier que le produit est bien en rupture
        $product = $data->getProduct();
        if ($product->getStock() > 0) {
            throw new BadRequestHttpException('Ce produit est déjà en stock.');
        }

        $this->em->persist($data);
        $this->em->flush();

        $this->logger->info('Stock alert created', [
            'alert_id' => $data->getId(),
            'user_id' => $user->getId(),
            'product_id' => $product->getId(),
        ]);

        return $data;
    }
}