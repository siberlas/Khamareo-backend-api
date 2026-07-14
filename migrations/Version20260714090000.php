<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Historique des messages envoyés manuellement par l'admin au client depuis
 * le détail d'une commande (objet + texte libre + pièce jointe optionnelle).
 */
final class Version20260714090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create order_message table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE order_message (id SERIAL NOT NULL, order_id UUID NOT NULL, subject VARCHAR(200) NOT NULL, message TEXT NOT NULL, attachment_path VARCHAR(255) DEFAULT NULL, attachment_filename VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_ORDER_MESSAGE_ORDER ON order_message (order_id)');
        $this->addSql('COMMENT ON COLUMN order_message.order_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE order_message ADD CONSTRAINT FK_ORDER_MESSAGE_ORDER FOREIGN KEY (order_id) REFERENCES "order" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE order_message DROP CONSTRAINT FK_ORDER_MESSAGE_ORDER');
        $this->addSql('DROP TABLE order_message');
    }
}
