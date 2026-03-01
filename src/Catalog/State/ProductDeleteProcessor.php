<?php

namespace App\Catalog\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Catalog\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Processor pour gérer le soft delete des produits via API Platform
 * 
 * Intercepte les DELETE sur Product et applique un soft delete
 * au lieu d'une vraie suppression
 */
final class ProductDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private ProcessorInterface $persistProcessor,
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {}

    /**
     * @param Product $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        // Si ce n'est pas une opération DELETE, déléguer au processor par défaut
        if (!$operation instanceof DeleteOperationInterface) {
            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        }

        // Vérifier que c'est bien un Product
        if (!$data instanceof Product) {
            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        }

        // Soft delete au lieu de suppression réelle
        $data->setIsDeleted(true);
        
        $this->em->flush();

        $this->logger->info('Product soft deleted via API Platform', [
            'product_id' => $data->getId()?->toRfc4122(),
            'product_slug' => $data->getSlug(),
            'product_name' => $data->getName(),
        ]);

        // Retourner null indique à API Platform que la suppression est réussie
        return null;
    }
}