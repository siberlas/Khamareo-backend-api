<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251214155526 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajouter guest_expires_at et has_accepted_guest_terms à User';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "user" ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD guest_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD has_accepted_guest_terms BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql("
            UPDATE \"user\" 
            SET guest_expires_at = created_at + INTERVAL '30 days',
                has_accepted_guest_terms = TRUE
            WHERE is_guest = TRUE AND guest_expires_at IS NULL
        ");
        $this->addSql('COMMENT ON COLUMN "user".updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN "user".guest_expires_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE "user" DROP updated_at');
        $this->addSql('ALTER TABLE "user" DROP guest_expires_at');
        $this->addSql('ALTER TABLE "user" DROP has_accepted_guest_terms');
    }
}
