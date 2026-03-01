<?php

namespace App\Shared\Enum;

enum DeliveryIssueType: string
{
    case LOST = 'lost';                        // Colis perdu
    case DAMAGED = 'damaged';                  // Colis endommagé
    case WRONG_ADDRESS = 'wrong_address';      // Adresse erronée
    case RETURNED = 'returned';                // Retourné à l'expéditeur
    case CUSTOMER_REFUSED = 'customer_refused'; // Client a refusé le colis

    public function label(): string
    {
        return match($this) {
            self::LOST => 'Colis perdu',
            self::DAMAGED => 'Colis endommagé',
            self::WRONG_ADDRESS => 'Adresse erronée',
            self::RETURNED => 'Retourné à l\'expéditeur',
            self::CUSTOMER_REFUSED => 'Refusé par le client',
        };
    }

    public function description(): string
    {
        return match($this) {
            self::LOST => 'Le colis n\'a pas été retrouvé par le transporteur',
            self::DAMAGED => 'Le colis a été endommagé pendant le transport',
            self::WRONG_ADDRESS => 'L\'adresse de livraison est incorrecte ou introuvable',
            self::RETURNED => 'Le colis a été retourné à l\'expéditeur après échec de livraison',
            self::CUSTOMER_REFUSED => 'Le client a refusé de réceptionner le colis',
        };
    }
}