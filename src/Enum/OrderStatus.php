<?php

namespace App\Enum;

enum OrderStatus: string
{
    case PENDING = 'pending';        // Commande créée, en attente de paiement
    case PAID = 'paid';              // Payée, en attente de traitement
    case PREPARING = 'preparing';    // En cours de préparation
    case SHIPPED = 'shipped';        // Expédiée
    case DELIVERED = 'delivered';    // Livrée
    case CANCELLED = 'cancelled';    // Annulée
    case REFUNDED = 'refunded';      // Remboursée
    case FAILED = 'failed';

    public function label(): string
    {
        return match($this) {
            self::PENDING => 'En attente',
            self::PAID => 'Payée',
            self::PREPARING => 'En préparation',
            self::SHIPPED => 'Expédiée',
            self::DELIVERED => 'Livrée',
            self::CANCELLED => 'Annulée',
            self::REFUNDED => 'Remboursée',
            self::FAILED => 'failed',
        };
    }

    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->label()] = $case->value;
        }
        return $choices;
    }
}
