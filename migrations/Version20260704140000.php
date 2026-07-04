<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260704140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refactor contact messages into conversations (thread system)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE contact_conversation (
            id SERIAL NOT NULL,
            email VARCHAR(180) NOT NULL,
            name VARCHAR(100) NOT NULL,
            subject VARCHAR(200) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            last_message_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            has_new BOOLEAN NOT NULL DEFAULT FALSE,
            is_read BOOLEAN NOT NULL DEFAULT FALSE,
            admin_notes TEXT DEFAULT NULL,
            PRIMARY KEY(id)
        )");

        $this->addSql('ALTER TABLE contact_message ADD COLUMN IF NOT EXISTS conversation_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE contact_message ADD COLUMN IF NOT EXISTS is_admin_reply BOOLEAN NOT NULL DEFAULT FALSE');

        // Une conversation par email (basée sur le premier message reçu)
        $this->addSql("
            INSERT INTO contact_conversation (email, name, subject, created_at, last_message_at, has_new, is_read)
            SELECT DISTINCT ON (email) email, name, subject, created_at, created_at, false, is_read
            FROM contact_message
            ORDER BY email, created_at ASC
        ");

        // Relier les messages existants à leur conversation
        $this->addSql("
            UPDATE contact_message cm
            SET conversation_id = cc.id
            FROM contact_conversation cc
            WHERE cc.email = cm.email
        ");

        // Mettre à jour last_message_at avec le message le plus récent
        $this->addSql("
            UPDATE contact_conversation cc
            SET last_message_at = sub.last_at
            FROM (
                SELECT conversation_id, MAX(created_at) AS last_at
                FROM contact_message
                GROUP BY conversation_id
            ) sub
            WHERE cc.id = sub.conversation_id
        ");

        // Rendre conversation_id NOT NULL + FK
        $this->addSql('ALTER TABLE contact_message ALTER COLUMN conversation_id SET NOT NULL');
        $this->addSql('ALTER TABLE contact_message ADD CONSTRAINT fk_contact_message_conversation FOREIGN KEY (conversation_id) REFERENCES contact_conversation (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_contact_message_conversation ON contact_message (conversation_id)');

        // Supprimer les anciennes colonnes devenues obsolètes
        $this->addSql('ALTER TABLE contact_message DROP COLUMN IF EXISTS admin_reply');
        $this->addSql('ALTER TABLE contact_message DROP COLUMN IF EXISTS replied_at');
        $this->addSql('ALTER TABLE contact_message DROP COLUMN IF EXISTS admin_notes');
        $this->addSql('ALTER TABLE contact_message DROP COLUMN IF EXISTS is_processed');
        $this->addSql('ALTER TABLE contact_message DROP COLUMN IF EXISTS is_read');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact_message DROP CONSTRAINT IF EXISTS fk_contact_message_conversation');
        $this->addSql('DROP INDEX IF EXISTS idx_contact_message_conversation');
        $this->addSql('ALTER TABLE contact_message DROP COLUMN IF EXISTS conversation_id');
        $this->addSql('ALTER TABLE contact_message DROP COLUMN IF EXISTS is_admin_reply');
        $this->addSql('ALTER TABLE contact_message ADD COLUMN IF NOT EXISTS admin_reply TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE contact_message ADD COLUMN IF NOT EXISTS replied_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE contact_message ADD COLUMN IF NOT EXISTS admin_notes TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE contact_message ADD COLUMN IF NOT EXISTS is_processed BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE contact_message ADD COLUMN IF NOT EXISTS is_read BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('DROP TABLE IF EXISTS contact_conversation');
    }
}
