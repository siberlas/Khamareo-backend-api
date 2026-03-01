<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lien de désabonnement newsletter (CNIL) : ajout de unsubscribe_token sur newsletter_subscriber.
 * Les abonnés existants reçoivent un token généré depuis leur id.
 */
final class Version20260220230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Newsletter unsubscribe token (CNIL compliance)';
    }

    public function up(Schema $schema): void
    {
        // Ajout de la colonne nullable d'abord pour les abonnés existants
        $this->addSql('ALTER TABLE newsletter_subscriber ADD unsubscribe_token VARCHAR(64) DEFAULT NULL');

        // Générer un token unique pour chaque abonné existant (md5 de id + random)
        $this->addSql("UPDATE newsletter_subscriber SET unsubscribe_token = md5(id::text || random()::text) WHERE unsubscribe_token IS NULL");

        // Rendre la colonne NOT NULL + unique maintenant que tous ont un token
        $this->addSql('ALTER TABLE newsletter_subscriber ALTER COLUMN unsubscribe_token SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_newsletter_unsubscribe_token ON newsletter_subscriber (unsubscribe_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_newsletter_unsubscribe_token');
        $this->addSql('ALTER TABLE newsletter_subscriber DROP unsubscribe_token');
    }
}
