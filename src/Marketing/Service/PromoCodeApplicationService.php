<?php

namespace App\Marketing\Service;

use App\Marketing\Entity\PromoCode;
use App\Cart\Entity\Cart;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class PromoCodeApplicationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
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
     * Applique le code promo au panier (sur les items uniquement)
     */
    public function applyToCart(Cart $cart, PromoCode $promoCode): void
    {
        $this->logger->info('🎟️ Application code promo au panier', [
            'cart_id' => $cart->getId(),
            'promo_code' => $promoCode->getCode(),
            'promo_type' => $promoCode->getType(),
            'current_promo' => $cart->getPromoCode()
        ]);

        // Validation du code promo
        if (!$promoCode->isValid()) {
            $this->logger->warning('⚠️ Tentative utilisation code promo invalide', [
                'cart_id' => $cart->getId(),
                'promo_code' => $promoCode->getCode(),
                'is_used' => $promoCode->isUsed(),
                'expires_at' => $promoCode->getExpiresAt()?->format('Y-m-d H:i:s'),
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')
            ]);

            throw new \InvalidArgumentException('Ce code promo n\'est pas valide');
        }

        // Validation du panier
        if ($cart->getItems()->isEmpty()) {
            $this->logger->warning('⚠️ Tentative application promo sur panier vide', [
                'cart_id' => $cart->getId(),
                'promo_code' => $promoCode->getCode()
            ]);

            throw new \InvalidArgumentException('Impossible d\'appliquer un code promo sur un panier vide');
        }

        try {
            // Calculer le sous-total des items (SANS shipping)
            $itemsSubtotal = $cart->getSubtotal();

            $this->logger->debug('📊 Sous-total panier calculé', [
                'cart_id' => $cart->getId(),
                'items_subtotal' => $itemsSubtotal,
                'items_count' => $cart->getItems()->count()
            ]);

            if ($itemsSubtotal <= 0) {
                $this->logger->error('❌ Sous-total invalide pour application promo', [
                    'cart_id' => $cart->getId(),
                    'items_subtotal' => $itemsSubtotal
                ]);

                throw new \RuntimeException('Le montant du panier est invalide');
            }

            // Calculer la réduction
            $discount = $this->calculateDiscount($promoCode, $itemsSubtotal);

            if ($discount <= 0) {
                $this->logger->warning('⚠️ Réduction calculée nulle ou négative', [
                    'cart_id' => $cart->getId(),
                    'promo_code' => $promoCode->getCode(),
                    'discount' => $discount,
                    'items_subtotal' => $itemsSubtotal
                ]);

                throw new \RuntimeException('Ce code promo n\'offre aucune réduction pour votre panier');
            }

            // Vérifier si un code promo était déjà appliqué
            $previousPromo = $cart->getPromoCode();
            $previousDiscount = $cart->getDiscountAmount();

            if ($previousPromo) {
                $this->logger->info('🔄 Remplacement code promo existant', [
                    'cart_id' => $cart->getId(),
                    'previous_promo' => $previousPromo,
                    'previous_discount' => $previousDiscount,
                    'new_promo' => $promoCode->getCode(),
                    'new_discount' => $discount
                ]);
            }

            // Stocker dans le panier
            $cart->setPromoCode($promoCode->getCode());
            $cart->setDiscountAmount((string) $discount);

            $this->entityManager->flush();

            $this->logger->info('✅ Code promo appliqué avec succès au panier', [
                'cart_id' => $cart->getId(),
                'promo_code' => $promoCode->getCode(),
                'discount_amount' => $discount,
                'items_subtotal' => $itemsSubtotal,
                'subtotal_after_discount' => $itemsSubtotal - $discount,
                'savings_percentage' => round(($discount / $itemsSubtotal) * 100, 2)
            ]);

        } catch (\InvalidArgumentException $e) {
            // Re-throw les exceptions de validation
            throw $e;

        } catch (\RuntimeException $e) {
            // Re-throw les exceptions métier
            throw $e;

        } catch (\Doctrine\DBAL\Exception $e) {
            $this->logger->critical('🔥 Erreur base de données lors application promo', [
                'cart_id' => $cart->getId(),
                'promo_code' => $promoCode->getCode(),
                'error' => $e->getMessage(),
                'sql_state' => $e->getSQLState()
            ]);

            throw new \RuntimeException('Erreur lors de l\'enregistrement du code promo', 0, $e);

        } catch (\Exception $e) {
            $this->logger->critical('🔥 Erreur inattendue lors application promo', [
                'cart_id' => $cart->getId(),
                'promo_code' => $promoCode->getCode(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new \RuntimeException('Une erreur est survenue lors de l\'application du code promo', 0, $e);
        }
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
     * Retire le code promo d'un panier
     */
    public function removeFromCart(Cart $cart): void
    {
        $previousPromo = $cart->getPromoCode();
        $previousDiscount = $cart->getDiscountAmount();

        if (!$previousPromo) {
            $this->logger->debug('ℹ️ Aucun code promo à retirer du panier', [
                'cart_id' => $cart->getId()
            ]);
            return;
        }

        $this->logger->info('🗑️ Retrait code promo du panier', [
            'cart_id' => $cart->getId(),
            'promo_code' => $previousPromo,
            'discount_removed' => $previousDiscount
        ]);

        try {
            $cart->setPromoCode(null);
            $cart->setDiscountAmount(null);

            $this->entityManager->flush();

            $this->logger->info('✅ Code promo retiré du panier avec succès', [
                'cart_id' => $cart->getId(),
                'removed_promo' => $previousPromo,
                'removed_discount' => $previousDiscount
            ]);

        } catch (\Exception $e) {
            $this->logger->error('❌ Erreur lors du retrait du code promo', [
                'cart_id' => $cart->getId(),
                'promo_code' => $previousPromo,
                'error' => $e->getMessage()
            ]);

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