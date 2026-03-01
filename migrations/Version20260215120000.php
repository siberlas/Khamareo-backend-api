<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260215120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Promo codes advanced rules, recipients and redemptions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE promo_code ALTER email DROP NOT NULL');
        $this->addSql('ALTER TABLE promo_code ADD starts_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE promo_code ADD min_order_amount NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE promo_code ADD max_uses INT DEFAULT 1');
        $this->addSql('ALTER TABLE promo_code ADD max_uses_per_email INT DEFAULT NULL');
        $this->addSql('ALTER TABLE promo_code ADD eligible_customer VARCHAR(20) DEFAULT \'both\' NOT NULL');
        $this->addSql('ALTER TABLE promo_code ADD stackable BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE promo_code ADD first_order_only BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE promo_code ADD max_discount_amount NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE promo_code ADD usage_window_days INT DEFAULT NULL');
        $this->addSql('ALTER TABLE promo_code ADD auto_apply BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE promo_code ADD allowed_countries JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE promo_code ADD allowed_locales JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE promo_code ADD allowed_channels JSON DEFAULT NULL');

        $this->addSql('CREATE TABLE promo_code_recipient (id SERIAL NOT NULL, promo_code_id UUID NOT NULL, email VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_promo_recipient_email ON promo_code_recipient (promo_code_id, email)');
        $this->addSql('CREATE INDEX IDX_PROMO_RECIPIENT_PROMO ON promo_code_recipient (promo_code_id)');
        $this->addSql('COMMENT ON COLUMN promo_code_recipient.promo_code_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE promo_code_recipient ADD CONSTRAINT FK_PROMO_RECIPIENT_PROMO FOREIGN KEY (promo_code_id) REFERENCES promo_code (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE promo_code_redemption (id UUID NOT NULL, promo_code_id UUID NOT NULL, order_id UUID DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, customer_type VARCHAR(20) NOT NULL, discount_amount NUMERIC(10, 2) DEFAULT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_PROMO_REDEMPTION_PROMO ON promo_code_redemption (promo_code_id)');
        $this->addSql('CREATE INDEX IDX_PROMO_REDEMPTION_ORDER ON promo_code_redemption (order_id)');
        $this->addSql('COMMENT ON COLUMN promo_code_redemption.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN promo_code_redemption.promo_code_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN promo_code_redemption.order_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE promo_code_redemption ADD CONSTRAINT FK_PROMO_REDEMPTION_PROMO FOREIGN KEY (promo_code_id) REFERENCES promo_code (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE promo_code_redemption ADD CONSTRAINT FK_PROMO_REDEMPTION_ORDER FOREIGN KEY (order_id) REFERENCES "order" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE promo_code_redemption');
        $this->addSql('DROP TABLE promo_code_recipient');

        $this->addSql('ALTER TABLE promo_code DROP starts_at');
        $this->addSql('ALTER TABLE promo_code DROP min_order_amount');
        $this->addSql('ALTER TABLE promo_code DROP max_uses');
        $this->addSql('ALTER TABLE promo_code DROP max_uses_per_email');
        $this->addSql('ALTER TABLE promo_code DROP eligible_customer');
        $this->addSql('ALTER TABLE promo_code DROP stackable');
        $this->addSql('ALTER TABLE promo_code DROP first_order_only');
        $this->addSql('ALTER TABLE promo_code DROP max_discount_amount');
        $this->addSql('ALTER TABLE promo_code DROP usage_window_days');
        $this->addSql('ALTER TABLE promo_code DROP auto_apply');
        $this->addSql('ALTER TABLE promo_code DROP allowed_countries');
        $this->addSql('ALTER TABLE promo_code DROP allowed_locales');
        $this->addSql('ALTER TABLE promo_code DROP allowed_channels');
    }
}
