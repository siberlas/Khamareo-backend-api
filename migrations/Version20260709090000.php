<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Contrainte d'unicité sur payment.provider_payment_id : le webhook Stripe peut
 * désormais créer la commande lui-même (payment_intent.succeeded), en concurrence
 * possible avec l'appel front /api/cart/checkout ou une double livraison webhook.
 * Cette contrainte empêche la création de deux Payment/Order pour le même
 * PaymentIntent en cas de course ; le code applicatif intercepte la violation.
 */
final class Version20260709090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unique index on payment.provider_payment_id (idempotence webhook/checkout)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6D28840DB4D2A32E ON payment (provider_payment_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_6D28840DB4D2A32E');
    }
}
