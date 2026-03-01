<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Double opt-in newsletter : ajout de confirmation_token et confirmed_at sur newsletter_subscriber.
 */
final class Version20260220220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Double opt-in newsletter: confirmation_token + confirmed_at on newsletter_subscriber';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE newsletter_subscriber ADD confirmation_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE newsletter_subscriber ADD confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN newsletter_subscriber.confirmed_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_newsletter_confirmation_token ON newsletter_subscriber (confirmation_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_newsletter_confirmation_token');
        $this->addSql('ALTER TABLE newsletter_subscriber DROP confirmation_token');
        $this->addSql('ALTER TABLE newsletter_subscriber DROP confirmed_at');
    }
}
