<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Création de la table consent_log.
 * Journalisation des consentements RGPD (cookies, marketing opt-in, CGV).
 */
final class Version20260220260000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Création de la table consent_log (journalisation RGPD des consentements)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE consent_log (
                id UUID NOT NULL,
                user_id UUID DEFAULT NULL,
                guest_token VARCHAR(64) DEFAULT NULL,
                type VARCHAR(30) NOT NULL,
                version VARCHAR(50) DEFAULT NULL,
                choice VARCHAR(20) NOT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql("COMMENT ON COLUMN consent_log.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN consent_log.user_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN consent_log.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE INDEX idx_consent_log_user ON consent_log (user_id)');
        $this->addSql('CREATE INDEX idx_consent_log_guest ON consent_log (guest_token)');
        $this->addSql('CREATE INDEX idx_consent_log_type_date ON consent_log (type, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE consent_log');
    }
}
