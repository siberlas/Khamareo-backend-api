<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajout de confirmation_sent_at sur newsletter_subscriber
 * pour le garde-fou anti-abus du renvoi d'email de confirmation.
 */
final class Version20260220240000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add confirmation_sent_at to newsletter_subscriber (rate-limit resend guard)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE newsletter_subscriber ADD confirmation_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN newsletter_subscriber.confirmation_sent_at IS \'(DC2Type:datetime_immutable)\'');

        // Renseigner la date pour les abonnés existants non confirmés qui ont un token
        // (on considère que subscribed_at est l'approximation du dernier envoi)
        $this->addSql('
            UPDATE newsletter_subscriber
            SET confirmation_sent_at = subscribed_at
            WHERE confirmed_at IS NULL AND confirmation_token IS NOT NULL
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE newsletter_subscriber DROP COLUMN confirmation_sent_at');
    }
}
