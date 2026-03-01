<?php

namespace App\Shipping\Service;

use App\Order\Entity\Order;
use App\Order\Entity\OrderItem;
use App\Shipping\Entity\Parcel;
use App\Shipping\Entity\ParcelItem;
use App\Shipping\Repository\ParcelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ParcelManager
{
    public function __construct(
        private EntityManagerInterface $em,
        private ParcelRepository $parcelRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * ✅ Répartition automatique (par défaut)
     * MODIFIÉ : Ne supprime QUE les colis 'pending', garde les autres intacts
     */
    public function autoDistribute(Order $order, int $maxWeightGrams = 30000): array
    {
        $this->assertOrderEditable($order);

        $this->logger->info('Auto-distribution des produits en colis', [
            'order' => $order->getOrderNumber(),
            'maxWeightGrams' => $maxWeightGrams,
        ]);

        // ✅ MODIFIÉ : Supprimer SEULEMENT les colis 'pending'
        // Les colis confirmed/labeled/shipped sont préservés
        $keptParcels = [];
        foreach ($order->getParcels() as $existingParcel) {
            if ($existingParcel->getStatus() === 'pending') {
                $this->em->remove($existingParcel);
            } else {
                $keptParcels[] = $existingParcel;
            }
        }
        $this->em->flush();

        // Calculer les produits déjà alloués dans les colis conservés
        $alreadyAllocated = [];
        foreach ($keptParcels as $keptParcel) {
            foreach ($keptParcel->getItems() as $parcelItem) {
                $orderItem = $parcelItem->getOrderItem();
                $oid = $orderItem->getId()->toRfc4122();
                $alreadyAllocated[$oid] = ($alreadyAllocated[$oid] ?? 0) + $parcelItem->getQuantity();
            }
        }

        $parcels = [];
        $currentParcel = null;
        $currentWeightGrams = 0;
        $parcelNumber = count($keptParcels) + 1; // Continuer la numérotation

        foreach ($order->getItems() as $orderItem) {
            $product = $orderItem->getProduct();
            $totalQuantity = (int) $orderItem->getQuantity();

            // ✅ Soustraire la quantité déjà allouée dans les colis conservés
            $oid = $orderItem->getId()->toRfc4122();
            $alreadyAllocatedQty = $alreadyAllocated[$oid] ?? 0;
            $remainingQuantity = $totalQuantity - $alreadyAllocatedQty;

            if ($remainingQuantity <= 0) {
                continue; // Produit déjà entièrement alloué
            }

            $unitWeightGrams = $this->getProductWeightGrams($product);

            while ($remainingQuantity > 0) {
                if ($currentParcel === null) {
                    $currentParcel = $this->createParcel($order, $parcelNumber++);
                    $currentWeightGrams = 0;
                    $parcels[] = $currentParcel;
                    $this->em->persist($currentParcel);
                }

                $availableWeightGrams = $maxWeightGrams - $currentWeightGrams;
                $safeUnitWeight = max(1, $unitWeightGrams);
                $maxQuantityCanAdd = (int) floor($availableWeightGrams / $safeUnitWeight);

                if ($maxQuantityCanAdd <= 0) {
                    $currentParcel->setWeightGrams($currentWeightGrams);
                    $currentParcel = null;
                    continue;
                }

                $quantityToAdd = min($remainingQuantity, $maxQuantityCanAdd);

                $parcelItem = (new ParcelItem())
                    ->setParcel($currentParcel)
                    ->setOrderItem($orderItem)
                    ->setQuantity($quantityToAdd);

                $currentParcel->addItem($parcelItem);
                $this->em->persist($parcelItem);

                $currentWeightGrams += ($safeUnitWeight * $quantityToAdd);
                $remainingQuantity -= $quantityToAdd;
            }
        }

        if ($currentParcel !== null) {
            $currentParcel->setWeightGrams($currentWeightGrams);
        }

        $this->em->flush();

        return $parcels;
    }

    /**
     * ✅ Crée un colis vide (manuel)
     * SIMPLIFIÉ : Plus de vérifications globales
     */
    public function createEmptyParcel(Order $order): Parcel
    {
        $this->assertOrderEditable($order);

        $parcelNumber = $order->getParcels()->count() + 1;

        $parcel = (new Parcel())
            ->setOrder($order)
            ->setParcelNumber($parcelNumber)
            ->setStatus('pending')
            ->setWeightGrams(0);

        $this->em->persist($parcel);
        $this->em->flush();

        return $parcel;
    }

    /**
     * ✅ Ajoute un OrderItem dans un colis (manuel)
     * MODIFIÉ : Vérifie le status du colis, invalide seulement ce colis
     */
    public function addItemToParcel(Parcel $parcel, OrderItem $orderItem, int $quantity): ParcelItem
    {
        $order = $parcel->getOrder();
        $this->assertOrderEditable($order);
        $this->assertParcelEditable($parcel); // ✅ Vérifie ce colis uniquement

        if ($quantity <= 0) {
            throw new \RuntimeException('La quantité doit être > 0', 400);
        }

        // ✅ Invalide l'étiquette de CE colis si elle existe
        $this->invalidateSingleParcelLabel($parcel);

        // Vérifier que l'OrderItem appartient à cette commande
        if ($orderItem->getCustomerOrder() !== $order) {
            throw new \RuntimeException('Ce produit n\'appartient pas à cette commande', 400);
        }

        // Quantité disponible
        $alreadyAllocated = $this->getItemAllocatedQuantity($orderItem, $order);
        $available = (int) $orderItem->getQuantity() - $alreadyAllocated;

        if ($quantity > $available) {
            throw new \RuntimeException(sprintf(
                'Quantité insuffisante. Disponible: %d, demandé: %d',
                $available,
                $quantity
            ), 400);
        }

        // Si un ParcelItem existe déjà pour ce OrderItem dans ce colis, on cumule
        foreach ($parcel->getItems() as $existing) {
            if ($existing->getOrderItem() === $orderItem) {
                $existing->setQuantity($existing->getQuantity() + $quantity);
                $this->recalculateParcelWeight($parcel);
                $this->em->flush();
                return $existing;
            }
        }

        $parcelItem = (new ParcelItem())
            ->setParcel($parcel)
            ->setOrderItem($orderItem)
            ->setQuantity($quantity);

        $parcel->addItem($parcelItem);
        $this->em->persist($parcelItem);

        $this->recalculateParcelWeight($parcel);
        $this->em->flush();

        return $parcelItem;
    }

    /**
     * ✅ Modifie la quantité d'un ParcelItem
     * MODIFIÉ : Vérifie le colis, invalide seulement ce colis
     */
    public function updateParcelItemQuantity(ParcelItem $parcelItem, int $newQuantity): void
    {
        $parcel = $parcelItem->getParcel();
        $order = $parcel->getOrder();

        $this->assertOrderEditable($order);
        $this->assertParcelEditable($parcel); // ✅ Vérifie ce colis uniquement

        if ($newQuantity < 0) {
            throw new \RuntimeException('La quantité ne peut pas être négative', 400);
        }

        // ✅ Invalide l'étiquette de CE colis si elle existe
        $this->invalidateSingleParcelLabel($parcel);

        $orderItem = $parcelItem->getOrderItem();
        if (!$orderItem) {
            throw new \RuntimeException('OrderItem introuvable sur ce ParcelItem', 500);
        }

        // Calculer l'allocation actuelle hors ce ParcelItem
        $allocatedWithoutThis = $this->getItemAllocatedQuantity($orderItem, $order) - $parcelItem->getQuantity();
        $available = (int) $orderItem->getQuantity() - $allocatedWithoutThis;

        if ($newQuantity > $available) {
            throw new \RuntimeException(sprintf(
                'Quantité insuffisante. Disponible: %d, demandé: %d',
                $available,
                $newQuantity
            ), 400);
        }

        if ($newQuantity === 0) {
            $parcel->removeItem($parcelItem);
            $this->em->remove($parcelItem);
        } else {
            $parcelItem->setQuantity($newQuantity);
        }

        $this->recalculateParcelWeight($parcel);
        $this->em->flush();
    }

    /**
     * ✅ Supprime un item d'un colis
     * MODIFIÉ : Vérifie le colis, invalide seulement ce colis
     */
    public function removeItemFromParcel(ParcelItem $parcelItem): void
    {
        $parcel = $parcelItem->getParcel();
        $order = $parcel->getOrder();

        $this->assertOrderEditable($order);
        $this->assertParcelEditable($parcel); // ✅ Vérifie ce colis uniquement

        // ✅ Invalide l'étiquette de CE colis si elle existe
        $this->invalidateSingleParcelLabel($parcel);

        $parcel->removeItem($parcelItem);
        $this->em->remove($parcelItem);

        $this->recalculateParcelWeight($parcel);
        $this->em->flush();
    }

    /**
     * ✅ Supprime un colis vide
     * MODIFIÉ : Vérifie le colis uniquement
     */
    public function deleteParcel(Parcel $parcel): void
    {
        $order = $parcel->getOrder();
        $this->assertOrderEditable($order);
        $this->assertParcelEditable($parcel); // ✅ Vérifie ce colis uniquement

        if ($parcel->getItems()->count() > 0) {
            throw new \RuntimeException('Impossible de supprimer un colis non vide', 400);
        }

        $this->em->remove($parcel);
        $this->em->flush();
    }

    /**
     * ✅ Valide un colis individuel
     * NOUVEAU : Vérifie poids, contenu, etc.
     */
    public function validateSingleParcel(Parcel $parcel): array
    {
        $order = $parcel->getOrder();
        $carrier = $order->getCarrier();

        if (!$carrier) {
            return [
                'valid' => false,
                'error' => 'Aucun transporteur sélectionné'
            ];
        }

        // Colis vide ?
        if ($parcel->getItems()->count() === 0) {
            return [
                'valid' => false,
                'error' => 'Le colis est vide'
            ];
        }

        // Poids OK ?
        $this->recalculateParcelWeight($parcel);
        $maxWeightGrams = (int) $carrier->getMaxWeightGrams();
        $weight = (int) ($parcel->getWeightGrams() ?? 0);

        if ($weight > $maxWeightGrams) {
            return [
                'valid' => false,
                'error' => sprintf(
                    'Poids max dépassé (%.2f kg > %.2f kg)',
                    $weight / 1000,
                    $maxWeightGrams / 1000
                )
            ];
        }

        return ['valid' => true];
    }

    /**
     * @deprecated Utiliser la confirmation par colis dans le controller
     * 
     * Confirme la répartition (niveau commande)
     * GARDÉ pour compatibilité mais déprécié
     */
    public function confirmParcels(Order $order): void
    {
        $this->assertOrderEditable($order);

        $carrier = $order->getCarrier();
        if (!$carrier) {
            throw new \RuntimeException('Aucun transporteur sélectionné', 400);
        }

        $maxWeightGrams = (int) $carrier->getMaxWeightGrams();

        $this->validateParcelsAllocation($order, $maxWeightGrams);

        $order->setParcelsConfirmed(true)
            ->setParcelsConfirmedAt(new \DateTimeImmutable());

        $this->em->flush();
    }

    /**
     * @deprecated Utiliser la déconfirmation par colis dans le controller
     * 
     * Déconfirme (retour brouillon)
     * GARDÉ pour compatibilité mais déprécié
     */
    public function unconfirmParcels(Order $order): void
    {
        $this->assertOrderEditable($order);

        $order->setParcelsConfirmed(false)
            ->setParcelsConfirmedAt(null);

        $this->em->flush();
    }

    /**
     * ✅ Valide la commande avec max par colis
     */
    public function validateOrderWeight(Order $order): array
    {
        $totalWeightGrams = $this->calculateOrderWeightGrams($order);
        $carrier = $order->getCarrier();

        if (!$carrier) {
            return [
                'valid' => false,
                'error' => 'Aucun transporteur sélectionné',
            ];
        }

        $maxWeightGrams = (int) $carrier->getMaxWeightGrams();
        $parcelsNeeded = (int) ceil(max(1, $totalWeightGrams) / max(1, $maxWeightGrams));

        return [
            'valid' => true,
            'totalWeightGrams' => $totalWeightGrams,
            'maxWeightGrams' => $maxWeightGrams,
            'parcelsNeeded' => $parcelsNeeded,
        ];
    }

    /**
     * Calcule le poids total d'une commande en grammes
     */
    public function calculateOrderWeightGrams(Order $order): int
    {
        $totalWeightGrams = 0;

        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            $qty = (int) $item->getQuantity();
            $unitWeightGrams = $this->getProductWeightGrams($product);
            $totalWeightGrams += ($unitWeightGrams * $qty);
        }

        return $totalWeightGrams;
    }

    // ===================== PRIVATE HELPERS =====================

    private function assertOrderEditable(Order $order): void
    {
        if (!$order->canEditParcels()) {
            throw new \RuntimeException('Commande verrouillée : modification impossible (statut).', 409);
        }
    }

    /**
     * ✅ NOUVEAU : Vérifie qu'un colis peut être modifié
     */
    private function assertParcelEditable(Parcel $parcel): void
    {
        if (!in_array($parcel->getStatus(), ['pending'], true)) {
            throw new \RuntimeException(
                sprintf(
                    'Ce colis ne peut plus être modifié (status: %s)',
                    $parcel->getStatus()
                ),
                409
            );
        }
    }

    /**
     * ✅ NOUVEAU : Invalide l'étiquette d'UN seul colis
     */
    private function invalidateSingleParcelLabel(Parcel $parcel): void
    {
        if (!$parcel->getTrackingNumber()) {
            return; // Pas d'étiquette à invalider
        }

        $this->logger->info('Invalidating label for parcel', [
            'parcel_id' => $parcel->getId()->toRfc4122(),
            'parcel_number' => $parcel->getParcelNumber(),
        ]);

        $parcel->setTrackingNumber(null);
        $parcel->setLabelPdfPath(null);
        $parcel->setDeliverySlipPdfPath(null);
        $parcel->setCn23PdfPath(null);
        $parcel->setLabelGeneratedAt(null);
        $parcel->setStatus('pending');

        $this->em->flush();
    }

    /**
     * ✅ RENOMMÉ : Invalide TOUTES les étiquettes (pour autoDistribute)
     */
    private function invalidateAllLabels(Order $order): void
    {
        $hasLabels = false;

        foreach ($order->getParcels() as $parcel) {
            if ($parcel->getTrackingNumber()) {
                $hasLabels = true;
                $parcel->setTrackingNumber(null);
                $parcel->setLabelPdfPath(null);
                $parcel->setDeliverySlipPdfPath(null);
                $parcel->setCn23PdfPath(null);
                $parcel->setLabelGeneratedAt(null);
                $parcel->setStatus('pending');
            }
        }

        if ($hasLabels) {
            $this->logger->info('All labels invalidated for order', [
                'order_id' => $order->getId()->toRfc4122(),
            ]);
        }

        $this->em->flush();
    }

    /**
     * Vérifie l'allocation complète (pour ancien workflow global)
     * GARDÉ pour confirmParcels() déprécié
     */
    private function validateParcelsAllocation(Order $order, int $maxWeightGrams): void
    {
        if ($order->getParcels()->count() === 0) {
            throw new \RuntimeException('Aucun colis : créez la répartition avant de confirmer.', 400);
        }

        foreach ($order->getParcels() as $parcel) {
            if ($parcel->getItems()->count() === 0) {
                throw new \RuntimeException(sprintf(
                    'Le colis #%d est vide : impossible de confirmer.',
                    $parcel->getParcelNumber()
                ), 400);
            }

            $this->recalculateParcelWeight($parcel);

            $w = (int) ($parcel->getWeightGrams() ?? 0);
            if ($w > $maxWeightGrams) {
                throw new \RuntimeException(sprintf(
                    'Le colis #%d dépasse le poids max (%.2f kg > %.2f kg).',
                    $parcel->getParcelNumber(),
                    $w / 1000,
                    $maxWeightGrams / 1000
                ), 400);
            }
        }

        // cohérence quantités
        foreach ($order->getItems() as $orderItem) {
            $allocated = $this->getItemAllocatedQuantity($orderItem, $order);
            $expected = (int) $orderItem->getQuantity();

            if ($allocated !== $expected) {
                throw new \RuntimeException(sprintf(
                    'Répartition incomplète pour "%s" : alloué %d / attendu %d.',
                    $orderItem->getProduct()?->getName() ?? 'Produit',
                    $allocated,
                    $expected
                ), 400);
            }
        }
    }

    private function getItemAllocatedQuantity(OrderItem $orderItem, Order $order): int
    {
        $allocated = 0;

        foreach ($order->getParcels() as $parcel) {
            foreach ($parcel->getItems() as $parcelItem) {
                if ($parcelItem->getOrderItem() === $orderItem) {
                    $allocated += $parcelItem->getQuantity();
                }
            }
        }

        return $allocated;
    }

    public function recalculateParcelWeight(Parcel $parcel): void
    {
        $total = 0;

        foreach ($parcel->getItems() as $item) {
            $total += (int) ($item->getTotalWeightGrams() ?? 0);
        }

        $parcel->setWeightGrams($total);
    }

    private function createParcel(Order $order, int $parcelNumber): Parcel
    {
        return (new Parcel())
            ->setOrder($order)
            ->setParcelNumber($parcelNumber)
            ->setStatus('pending');
    }

    private function getProductWeightGrams($product): int
    {
        $weight = $product->getWeight();

        if ($weight === null || !is_numeric($weight)) {
            return 500;
        }

        $weight = (float) $weight;

        if ($weight > 0 && $weight <= 30) {
            return max(1, (int) round($weight * 1000));
        }

        return max(1, (int) round($weight));
    }
}
