<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260217123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add home_quote table for homepage quotes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE home_quote (id UUID NOT NULL, text TEXT NOT NULL, author VARCHAR(255) DEFAULT NULL, origin VARCHAR(255) DEFAULT NULL, display_order INT NOT NULL, is_active BOOLEAN NOT NULL, start_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, end_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql("COMMENT ON COLUMN home_quote.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN home_quote.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN home_quote.updated_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN home_quote.start_date IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN home_quote.end_date IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE home_quote');
    }
}
