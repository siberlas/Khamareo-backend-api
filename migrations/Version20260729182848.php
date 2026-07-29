<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute delivery_address_id / billing_address_id sur cart — snapshot de la
 * dernière adresse saisie à l'étape paiement, pour permettre la reprise
 * d'une session invité interrompue (relance après échec de paiement) sans
 * ressaisie complète.
 */
final class Version20260729182848 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute cart.delivery_address_id / cart.billing_address_id (FK address, nullable)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cart ADD delivery_address_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cart ADD billing_address_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cart ADD CONSTRAINT FK_BA388B7EBF23851 FOREIGN KEY (delivery_address_id) REFERENCES address (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE cart ADD CONSTRAINT FK_BA388B779D0C0E4 FOREIGN KEY (billing_address_id) REFERENCES address (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_BA388B7EBF23851 ON cart (delivery_address_id)');
        $this->addSql('CREATE INDEX IDX_BA388B779D0C0E4 ON cart (billing_address_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cart DROP CONSTRAINT FK_BA388B7EBF23851');
        $this->addSql('ALTER TABLE cart DROP CONSTRAINT FK_BA388B779D0C0E4');
        $this->addSql('DROP INDEX IDX_BA388B7EBF23851');
        $this->addSql('DROP INDEX IDX_BA388B779D0C0E4');
        $this->addSql('ALTER TABLE cart DROP delivery_address_id');
        $this->addSql('ALTER TABLE cart DROP billing_address_id');
    }
}
