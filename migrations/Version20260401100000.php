<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260401100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create launch_email_queue table for batched launch email sending';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE launch_email_queue (
            id UUID NOT NULL,
            email VARCHAR(180) NOT NULL,
            promo_code VARCHAR(30) NOT NULL,
            status VARCHAR(20) DEFAULT \'pending\' NOT NULL,
            launch_date DATE NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('COMMENT ON COLUMN launch_email_queue.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN launch_email_queue.launch_date IS \'(DC2Type:date_immutable)\'');
        $this->addSql('COMMENT ON COLUMN launch_email_queue.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN launch_email_queue.sent_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX idx_launch_queue_status ON launch_email_queue (status)');
        $this->addSql('CREATE INDEX idx_launch_queue_status_created ON launch_email_queue (status, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE launch_email_queue');
    }
}
