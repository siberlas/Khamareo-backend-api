<?php

namespace App\Marketing\Service;

use App\Marketing\Entity\PromoCode;
use App\Marketing\Repository\PromoCodeRepository;
use App\Cart\Entity\Cart;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class PromoCodeApplicationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private PromoCodeRepository $promoCodeRepository,
    ) {
        $this->logger->debug('🔧 PromoCodeApplicationService initialisé');
    }

    /**
     * Calcule le montant de la réduction basé UNIQUEMENT sur le sous-total des items
     * (SANS les frais de livraison)
     */
    public function calculateDiscount(PromoCode $promoCode, float $itemsSubtotal): float
    {
        $this->logger->debug('🧮 Calcul de la réduction', [
            'promo_code' => $promoCode->getCode(),
            'items_subtotal' => $itemsSubtotal,
            'discount_percentage' => $promoCode->getDiscountPercentage(),
            'discount_amount' => $promoCode->getDiscountAmount()
        ]);

        if ($itemsSubtotal <= 0) {
            $this->logger->warning('⚠️ Sous-total invalide pour calcul réduction', [
                'promo_code' => $promoCode->getCode(),
                'items_subtotal' => $itemsSubtotal
            ]);
            return 0.0;
        }

        try {
            $discount = 0.0;

            if ($promoCode->getDiscountPercentage()) {
                $percentage = (float) $promoCode->getDiscountPercentage();
                $discount = ($itemsSubtotal * $percentage) / 100;
                
                $this->logger->debug('📊 Réduction en pourcentage calculée', [
                    'promo_code' => $promoCode->getCode(),
                    'percentage' => $percentage,
                    'discount_calculated' => $discount
                ]);
                
            } elseif ($promoCode->getDiscountAmount()) {
                $discount = (float) $promoCode->getDiscountAmount();
                
                $this->logger->debug('💰 Réduction en montant fixe appliquée', [
                    'promo_code' => $promoCode->getCode(),
                    'discount_amount' => $discount
                ]);
                
            } else {
                $this->logger->warning('⚠️ Code promo sans réduction définie', [
                    'promo_code' => $promoCode->getCode(),
                    'promo_id' => $promoCode->getId()
                ]);
            }

            // Appliquer le plafond maxDiscountAmount si défini
            if ($promoCode->getMaxDiscountAmount() !== null) {
                $discount = min($discount, (float) $promoCode->getMaxDiscountAmount());
            }

            // La réduction ne peut pas être supérieure au sous-total
            $finalDiscount = min($discount, $itemsSubtotal);

            if ($finalDiscount < $discount) {
                $this->logger->info('🔒 Réduction plafonnée au sous-total', [
                    'promo_code' => $promoCode->getCode(),
                    'calculated_discount' => $discount,
                    'capped_discount' => $finalDiscount,
                    'items_subtotal' => $itemsSubtotal
                ]);
            }

            $this->logger->info('✅ Réduction calculée avec succès', [
                'promo_code' => $promoCode->getCode(),
                'final_discount' => $finalDiscount,
                'items_subtotal' => $itemsSubtotal,
                'percentage_saved' => $itemsSubtotal > 0 ? round(($finalDiscount / $itemsSubtotal) * 100, 2) : 0
            ]);

            return $finalDiscount;

        } catch (\TypeError $e) {
            $this->logger->error('❌ Erreur de type lors calcul réduction', [
                'promo_code' => $promoCode->getCode(),
                'error' => $e->getMessage(),
                'discount_percentage' => $promoCode->getDiscountPercentage(),
                'discount_amount' => $promoCode->getDiscountAmount()
            ]);
            return 0.0;

        } catch (\Exception $e) {
            $this->logger->critical('🔥 Erreur inattendue lors calcul réduction', [
                'promo_code' => $promoCode->getCode(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 0.0;
        }
    }

    /**
     * Applique le code promo au panier.
     * Gère la logique de cumul (stackable) : si le nouveau code ET tous les codes
     * déjà appliqués sont cumulables, on accumule ; sinon on remplace tout.
     */
    public function applyToCart(Cart $cart, PromoCode $promoCode, ?string $appliedWithEmail = null): void
    {
        $this->logger->info('🎟️ Application code promo au panier', [
            'cart_id' => $cart->getId(),
            'promo_code' => $promoCode->getCode(),
            'stackable' => $promoCode->isStackable(),
            'current_promo' => $cart->getPromoCode(),
            'applied_with_email' => $appliedWithEmail,
        ]);

        if (!$promoCode->isValid()) {
            throw new \InvalidArgumentException('Ce code promo n\'est pas valide');
        }

        if ($cart->getItems()->isEmpty()) {
            throw new \InvalidArgumentException('Impossible d\'appliquer un code promo sur un panier vide');
        }

        try {
            $itemsSubtotal = $cart->getSubtotal();

            if ($itemsSubtotal <= 0) {
                throw new \RuntimeException('Le montant du panier est invalide');
            }

            if ($promoCode->getMinOrderAmount() !== null) {
                $minAmount = (float) $promoCode->getMinOrderAmount();
                if ($itemsSubtotal < $minAmount) {
                    throw new \InvalidArgumentException(
                        sprintf('Ce code promo nécessite un panier d\'au moins %.2f €', $minAmount)
                    );
                }
            }

            $discount = $this->calculateDiscount($promoCode, $itemsSubtotal);

            if ($discount <= 0) {
                throw new \RuntimeException('Ce code promo n\'offre aucune réduction pour votre panier');
            }

            // ── Logique de cumul ──────────────────────────────────────────────
            $existingData = $cart->getPromoCodesData() ?? [];

            // Vérifier que le code n'est pas déjà appliqué
            foreach ($existingData as $item) {
                if ($item['code'] === $promoCode->getCode()) {
                    throw new \InvalidArgumentException('Ce code promo est déjà appliqué');
                }
            }

            $newItem = [
                'code'             => $promoCode->getCode(),
                'discount'         => $discount,
                'stackable'        => $promoCode->isStackable(),
                'appliedWithEmail' => $appliedWithEmail,
            ];

            if (!empty($existingData)) {
                if (!$promoCode->isStackable()) {
                    // Le nouveau code n'est pas cumulable → il remplace tout
                    $existingData = [$newItem];
                    $this->logger->info('🔄 Code non cumulable remplace les précédents', ['new_promo' => $promoCode->getCode()]);
                } else {
                    // Le nouveau code est cumulable → vérifier les codes existants depuis la DB (valeur actuelle)
                    $nonStackable = $this->getNonStackableCodesFromDB($existingData);
                    if (!empty($nonStackable)) {
                        throw new \InvalidArgumentException(
                            'Ce code promo ne peut pas être combiné avec : ' . implode(', ', $nonStackable)
                        );
                    }
                    $existingData[] = $newItem;
                    $this->logger->info('✅ Code promo cumulé', ['codes' => array_column($existingData, 'code')]);
                }
            } else {
                $existingData = [$newItem];
            }

            $cart->setPromoCodesData($existingData);
            $this->rebuildCartFields($cart);
            $this->entityManager->flush();

        } catch (\InvalidArgumentException | \RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->critical('🔥 Erreur lors application promo', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Une erreur est survenue lors de l\'application du code promo', 0, $e);
        }
    }

    /**
     * Retourne les codes non cumulables parmi ceux stockés dans le panier,
     * en vérifiant la valeur ACTUELLE en base (pas le JSON potentiellement périmé).
     */
    private function getNonStackableCodesFromDB(array $promoCodesData): array
    {
        $nonStackable = [];
        foreach ($promoCodesData as $item) {
            $code = $item['code'] ?? null;
            if (!$code) continue;
            $existing = $this->promoCodeRepository->findOneBy(['code' => $code]);
            if ($existing && !$existing->isStackable()) {
                $nonStackable[] = $code;
            }
        }
        return $nonStackable;
    }

    /**
     * Reconstruit promoCode (dernier code) et discountAmount (somme) depuis promoCodesData.
     */
    private function rebuildCartFields(Cart $cart): void
    {
        $data = $cart->getPromoCodesData() ?? [];
        if (empty($data)) {
            $cart->setPromoCode(null);
            $cart->setDiscountAmount(null);
            return;
        }
        $last = end($data);
        $cart->setPromoCode($last['code']);
        $total = array_sum(array_column($data, 'discount'));
        $cart->setDiscountAmount((string) round($total, 2));
    }

    /**
     * Marque le code promo comme utilisé
     */
    public function markAsUsed(PromoCode $promoCode): void
    {
        $this->logger->info('🔒 Marquage code promo comme utilisé', [
            'promo_code' => $promoCode->getCode(),
            'promo_id' => $promoCode->getId(),
            'email' => $promoCode->getEmail(),
            'type' => $promoCode->getType()
        ]);

        // Vérifier si déjà utilisé
        if ($promoCode->isUsed()) {
            $this->logger->warning('⚠️ Code promo déjà marqué comme utilisé', [
                'promo_code' => $promoCode->getCode(),
                'used_at' => $promoCode->getUsedAt()?->format('Y-m-d H:i:s')
            ]);

            // Ne pas lever d'exception, juste logger et continuer
            return;
        }

        try {
            $now = new \DateTimeImmutable();
            
            $promoCode->setIsUsed(true);
            $promoCode->setUsedAt($now);

            $this->entityManager->flush();

            $this->logger->info('✅ Code promo marqué comme utilisé avec succès', [
                'promo_code' => $promoCode->getCode(),
                'used_at' => $now->format('Y-m-d H:i:s'),
                'email' => $promoCode->getEmail()
            ]);

        } catch (\Doctrine\DBAL\Exception $e) {
            $this->logger->critical('🔥 Erreur base de données lors marquage promo comme utilisé', [
                'promo_code' => $promoCode->getCode(),
                'error' => $e->getMessage(),
                'sql_state' => $e->getSQLState()
            ]);

            throw new \RuntimeException('Erreur lors du marquage du code promo', 0, $e);

        } catch (\Exception $e) {
            $this->logger->critical('🔥 Erreur inattendue lors marquage promo', [
                'promo_code' => $promoCode->getCode(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new \RuntimeException('Erreur lors du marquage du code promo', 0, $e);
        }
    }

    /**
     * Annule l'utilisation d'un code promo
     */
    public function cancelPromoCode(PromoCode $promoCode): void
    {
        $this->logger->info('🔓 Annulation utilisation code promo', [
            'promo_code' => $promoCode->getCode(),
            'promo_id' => $promoCode->getId(),
            'was_used' => $promoCode->isUsed(),
            'previous_used_at' => $promoCode->getUsedAt()?->format('Y-m-d H:i:s')
        ]);

        // Vérifier si le code était effectivement utilisé
        if (!$promoCode->isUsed()) {
            $this->logger->warning('⚠️ Tentative annulation code promo non utilisé', [
                'promo_code' => $promoCode->getCode()
            ]);

            // Ne pas lever d'exception, juste logger
            return;
        }

        try {
            $promoCode->setIsUsed(false);
            $promoCode->setUsedAt(null);

            $this->entityManager->flush();

            $this->logger->info('✅ Utilisation code promo annulée avec succès', [
                'promo_code' => $promoCode->getCode(),
                'email' => $promoCode->getEmail(),
                'type' => $promoCode->getType()
            ]);

        } catch (\Doctrine\DBAL\Exception $e) {
            $this->logger->critical('🔥 Erreur base de données lors annulation promo', [
                'promo_code' => $promoCode->getCode(),
                'error' => $e->getMessage(),
                'sql_state' => $e->getSQLState()
            ]);

            throw new \RuntimeException('Erreur lors de l\'annulation du code promo', 0, $e);

        } catch (\Exception $e) {
            $this->logger->critical('🔥 Erreur inattendue lors annulation promo', [
                'promo_code' => $promoCode->getCode(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new \RuntimeException('Erreur lors de l\'annulation du code promo', 0, $e);
        }
    }

    /**
     * Retire un code promo du panier.
     * Si $codeToRemove est null, retire tous les codes.
     * Si $codeToRemove est fourni, retire uniquement ce code (les autres restent).
     */
    public function removeFromCart(Cart $cart, ?string $codeToRemove = null): void
    {
        $this->logger->info('🗑️ Retrait code promo du panier', [
            'cart_id'        => $cart->getId(),
            'code_to_remove' => $codeToRemove ?? 'ALL',
        ]);

        try {
            if ($codeToRemove === null) {
                // Supprimer tous les codes
                $cart->setPromoCodesData(null);
                $cart->setPromoCode(null);
                $cart->setDiscountAmount(null);
            } else {
                $data = $cart->getPromoCodesData() ?? [];
                $data = array_values(array_filter($data, fn($item) => $item['code'] !== $codeToRemove));
                $cart->setPromoCodesData(empty($data) ? null : $data);
                $this->rebuildCartFields($cart);
            }

            $this->entityManager->flush();

            $this->logger->info('✅ Code promo retiré du panier', [
                'cart_id'        => $cart->getId(),
                'removed'        => $codeToRemove ?? 'ALL',
                'remaining_data' => $cart->getPromoCodesData(),
            ]);

        } catch (\Exception $e) {
            $this->logger->error('❌ Erreur lors du retrait du code promo', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Erreur lors du retrait du code promo', 0, $e);
        }
    }

    /**
     * Valide qu'un code promo est applicable à un panier
     */
    public function validateForCart(Cart $cart, PromoCode $promoCode): array
    {
        $this->logger->debug('🔍 Validation code promo pour panier', [
            'cart_id' => $cart->getId(),
            'promo_code' => $promoCode->getCode()
        ]);

        $errors = [];

        // Vérifier la validité du code
        if (!$promoCode->isValid()) {
            $errors[] = 'Ce code promo n\'est plus valide';
            
            $this->logger->warning('⚠️ Code promo invalide', [
                'promo_code' => $promoCode->getCode(),
                'is_used' => $promoCode->isUsed(),
                'expires_at' => $promoCode->getExpiresAt()?->format('Y-m-d H:i:s')
            ]);
        }

        // Vérifier que le panier n'est pas vide
        if ($cart->getItems()->isEmpty()) {
            $errors[] = 'Votre panier est vide';
            
            $this->logger->warning('⚠️ Panier vide', [
                'cart_id' => $cart->getId()
            ]);
        }

        // Vérifier le montant minimum (si applicable)
        $itemsSubtotal = $cart->getSubtotal();
        if ($itemsSubtotal <= 0) {
            $errors[] = 'Le montant du panier est invalide';
        }

        $isValid = empty($errors);

        $this->logger->info($isValid ? '✅ Code promo valide pour le panier' : '❌ Code promo non valide', [
            'cart_id' => $cart->getId(),
            'promo_code' => $promoCode->getCode(),
            'is_valid' => $isValid,
            'errors' => $errors
        ]);

        return [
            'valid' => $isValid,
            'errors' => $errors
        ];
    }
}