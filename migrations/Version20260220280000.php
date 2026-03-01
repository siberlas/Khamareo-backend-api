<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Création de la table return_request.
 * Journalise les demandes de rétractation (Art. L221-18 Code de la consommation).
 */
final class Version20260220280000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Création de la table return_request (rétractation L221-18)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE return_request (
            id UUID NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            email VARCHAR(255) NOT NULL,
            order_number VARCHAR(100) DEFAULT NULL,
            reason TEXT DEFAULT NULL,
            status VARCHAR(20) DEFAULT 'pending' NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )");
        $this->addSql("COMMENT ON COLUMN return_request.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN return_request.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE INDEX idx_return_request_email ON return_request (email)');
        $this->addSql('CREATE INDEX idx_return_request_order ON return_request (order_number)');
        $this->addSql('CREATE INDEX idx_return_request_status ON return_request (status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE return_request');
    }
}
