<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260105160343 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE shipping_label ADD carrier_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE shipping_label ADD CONSTRAINT FK_E0388D5221DFC797 FOREIGN KEY (carrier_id) REFERENCES carrier (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_E0388D5221DFC797 ON shipping_label (carrier_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE shipping_label DROP CONSTRAINT FK_E0388D5221DFC797');
        $this->addSql('DROP INDEX IDX_E0388D5221DFC797');
        $this->addSql('ALTER TABLE shipping_label DROP carrier_id');
    }
}
