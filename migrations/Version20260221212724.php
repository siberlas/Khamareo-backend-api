<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260221212724 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cart ALTER last_reminder_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE cart ALTER last_guest_reminder_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN cart.last_reminder_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN cart.last_guest_reminder_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE cart_promo_code ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE cart_promo_code ALTER applied_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN cart_promo_code.applied_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER INDEX idx_cart_promo_code_cart RENAME TO IDX_5DE6E36C1AD5CDBF');
        $this->addSql('ALTER INDEX idx_cart_promo_code_promo RENAME TO IDX_5DE6E36C2FAE4625');
        $this->addSql('ALTER INDEX uniq_newsletter_confirmation_token RENAME TO UNIQ_401562C3C05FB297');
        $this->addSql('ALTER INDEX uniq_newsletter_unsubscribe_token RENAME TO UNIQ_401562C3E0674361');
        $this->addSql('ALTER TABLE "order" DROP CONSTRAINT fk_f5299398c7c2b5d1');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              "order"
            ADD
              CONSTRAINT FK_F52993982316A302 FOREIGN KEY (carrier_mode_id) REFERENCES carrier_mode (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql('ALTER INDEX idx_f5299398c7c2b5d1 RENAME TO IDX_F52993982316A302');
        $this->addSql('ALTER TABLE promo_code ALTER starts_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE promo_code ALTER max_uses DROP DEFAULT');
        $this->addSql('ALTER TABLE promo_code ALTER eligible_customer DROP DEFAULT');
        $this->addSql('ALTER TABLE promo_code ALTER stackable DROP DEFAULT');
        $this->addSql('ALTER TABLE promo_code ALTER first_order_only DROP DEFAULT');
        $this->addSql('ALTER TABLE promo_code ALTER auto_apply DROP DEFAULT');
        $this->addSql('COMMENT ON COLUMN promo_code.starts_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE promo_code_recipient ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE promo_code_recipient ALTER created_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN promo_code_recipient.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER INDEX idx_promo_recipient_promo RENAME TO IDX_C4C2C8F22FAE4625');
        $this->addSql('ALTER TABLE promo_code_redemption ALTER used_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN promo_code_redemption.used_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER INDEX idx_promo_redemption_promo RENAME TO IDX_707E98142FAE4625');
        $this->addSql('ALTER INDEX idx_promo_redemption_order RENAME TO IDX_707E98148D9F6D38');
        $this->addSql('ALTER TABLE refresh_tokens ALTER id DROP DEFAULT');
        $this->addSql('DROP INDEX idx_review_is_verified');
        $this->addSql('ALTER TABLE review DROP order_number');
        $this->addSql('DROP INDEX idx_4e03e2cff026bb7c');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE review ADD order_number VARCHAR(100) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_review_is_verified ON review (is_verified)');
        $this->addSql('ALTER INDEX uniq_401562c3c05fb297 RENAME TO uniq_newsletter_confirmation_token');
        $this->addSql('ALTER INDEX uniq_401562c3e0674361 RENAME TO uniq_newsletter_unsubscribe_token');
        $this->addSql('CREATE SEQUENCE refresh_tokens_id_seq');
        $this->addSql('SELECT setval(\'refresh_tokens_id_seq\', (SELECT MAX(id) FROM refresh_tokens))');
        $this->addSql('ALTER TABLE refresh_tokens ALTER id SET DEFAULT nextval(\'refresh_tokens_id_seq\')');
        $this->addSql('CREATE SEQUENCE cart_promo_code_id_seq');
        $this->addSql('SELECT setval(\'cart_promo_code_id_seq\', (SELECT MAX(id) FROM cart_promo_code))');
        $this->addSql('ALTER TABLE cart_promo_code ALTER id SET DEFAULT nextval(\'cart_promo_code_id_seq\')');
        $this->addSql('ALTER TABLE cart_promo_code ALTER applied_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN cart_promo_code.applied_at IS NULL');
        $this->addSql('ALTER INDEX idx_5de6e36c1ad5cdbf RENAME TO idx_cart_promo_code_cart');
        $this->addSql('ALTER INDEX idx_5de6e36c2fae4625 RENAME TO idx_cart_promo_code_promo');
        $this->addSql('CREATE INDEX idx_4e03e2cff026bb7c ON shipping_rate (country_code)');
        $this->addSql('ALTER TABLE promo_code_redemption ALTER used_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN promo_code_redemption.used_at IS NULL');
        $this->addSql('ALTER INDEX idx_707e98148d9f6d38 RENAME TO idx_promo_redemption_order');
        $this->addSql('ALTER INDEX idx_707e98142fae4625 RENAME TO idx_promo_redemption_promo');
        $this->addSql('CREATE SEQUENCE promo_code_recipient_id_seq');
        $this->addSql('SELECT setval(\'promo_code_recipient_id_seq\', (SELECT MAX(id) FROM promo_code_recipient))');
        $this->addSql('ALTER TABLE promo_code_recipient ALTER id SET DEFAULT nextval(\'promo_code_recipient_id_seq\')');
        $this->addSql('ALTER TABLE promo_code_recipient ALTER created_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN promo_code_recipient.created_at IS NULL');
        $this->addSql('ALTER INDEX idx_c4c2c8f22fae4625 RENAME TO idx_promo_recipient_promo');
        $this->addSql('ALTER TABLE promo_code ALTER starts_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE promo_code ALTER max_uses SET DEFAULT 1');
        $this->addSql('ALTER TABLE promo_code ALTER eligible_customer SET DEFAULT \'both\'');
        $this->addSql('ALTER TABLE promo_code ALTER stackable SET DEFAULT false');
        $this->addSql('ALTER TABLE promo_code ALTER first_order_only SET DEFAULT false');
        $this->addSql('ALTER TABLE promo_code ALTER auto_apply SET DEFAULT false');
        $this->addSql('COMMENT ON COLUMN promo_code.starts_at IS NULL');
        $this->addSql('ALTER TABLE cart ALTER last_reminder_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE cart ALTER last_guest_reminder_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN cart.last_reminder_at IS NULL');
        $this->addSql('COMMENT ON COLUMN cart.last_guest_reminder_at IS NULL');
        $this->addSql('ALTER TABLE "order" DROP CONSTRAINT FK_F52993982316A302');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              "order"
            ADD
              CONSTRAINT fk_f5299398c7c2b5d1 FOREIGN KEY (carrier_mode_id) REFERENCES carrier_mode (id) ON DELETE
            SET
              NULL NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql('ALTER INDEX idx_f52993982316a302 RENAME TO idx_f5299398c7c2b5d1');
    }
}
