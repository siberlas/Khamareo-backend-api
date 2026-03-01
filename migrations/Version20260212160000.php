<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260212160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add carrier_mode_id on order and detailed delivery delay fields on carrier_mode';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "order" ADD carrier_mode_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD CONSTRAINT FK_F5299398C7C2B5D1 FOREIGN KEY (carrier_mode_id) REFERENCES carrier_mode (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_F5299398C7C2B5D1 ON "order" (carrier_mode_id)');

        $this->addSql('ALTER TABLE carrier_mode ADD delivery_min_days INT DEFAULT NULL');
        $this->addSql('ALTER TABLE carrier_mode ADD delivery_max_days INT DEFAULT NULL');
        $this->addSql('ALTER TABLE carrier_mode ADD delivery_days_unit VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE carrier_mode ADD delivery_days_note TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE carrier_mode ADD colissimo_product_code_key VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "order" DROP CONSTRAINT FK_F5299398C7C2B5D1');
        $this->addSql('DROP INDEX IDX_F5299398C7C2B5D1');
        $this->addSql('ALTER TABLE "order" DROP carrier_mode_id');

        $this->addSql('ALTER TABLE carrier_mode DROP delivery_min_days');
        $this->addSql('ALTER TABLE carrier_mode DROP delivery_max_days');
        $this->addSql('ALTER TABLE carrier_mode DROP delivery_days_unit');
        $this->addSql('ALTER TABLE carrier_mode DROP delivery_days_note');
        $this->addSql('ALTER TABLE carrier_mode DROP colissimo_product_code_key');
    }
}
