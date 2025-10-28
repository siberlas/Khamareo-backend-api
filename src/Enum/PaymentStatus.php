<?php

namespace App\Enum;

enum PaymentStatus: string
{
    case PENDING = 'pending';     // Paiement en attente
    case AUTHORIZED = 'authorized'; // Autorisé (réservation)
    case SUCCEEDED = 'succeeded'; // Payé avec succès
    case FAILED = 'failed';       // Échec de paiement
    case REFUNDED = 'refunded';   // Remboursé
    case CANCELLED = 'cancelled'; // Annulé par l’utilisateur ou le commerçant

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'En attente',
            self::AUTHORIZED => 'Autorisé',
            self::SUCCEEDED => 'Réussi',
            self::FAILED => 'Échoué',
            self::REFUNDED => 'Remboursé',
            self::CANCELLED => 'Annulé',
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
