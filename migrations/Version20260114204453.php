<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260114204453 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX IF EXISTS idx_category_enabled_display');
        $this->addSql('DROP INDEX IF EXISTS idx_category_parent_enabled');
        $this->addSql('DROP INDEX IF EXISTS idx_category_slug');
        $this->addSql('DROP INDEX IF EXISTS idx_category_media_category_usage');
        $this->addSql('ALTER TABLE "order" ADD parcels_confirmed BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE "order" ADD parcels_confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD labels_invalidated BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE "order" ADD labels_invalidated_message TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD labels_invalidated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN "order".parcels_confirmed_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN "order".labels_invalidated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('DROP INDEX IF EXISTS idx_product_category_enabled');
        $this->addSql('DROP INDEX IF EXISTS idx_product_created');
        $this->addSql('DROP INDEX IF EXISTS idx_product_deleted');
        $this->addSql('DROP INDEX IF EXISTS idx_product_enabled_deleted_category');
        $this->addSql('DROP INDEX IF EXISTS idx_product_slug');
        $this->addSql('DROP INDEX IF EXISTS idx_product_media_display');
        $this->addSql('DROP INDEX IF EXISTS idx_product_media_product_primary');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('CREATE INDEX idx_product_media_display ON product_media (product_id, display_order)');
        $this->addSql('CREATE INDEX idx_product_media_product_primary ON product_media (product_id, is_primary)');
        $this->addSql('CREATE INDEX idx_category_enabled_display ON category (is_enabled, display_order)');
        $this->addSql('CREATE INDEX idx_category_parent_enabled ON category (parent_id, is_enabled)');
        $this->addSql('CREATE INDEX idx_category_slug ON category (slug)');
        $this->addSql('CREATE INDEX idx_product_category_enabled ON product (category_id, is_enabled)');
        $this->addSql('CREATE INDEX idx_product_created ON product (created_at)');
        $this->addSql('CREATE INDEX idx_product_deleted ON product (is_deleted, updated_at) WHERE (is_deleted = true)');
        $this->addSql('CREATE INDEX idx_product_enabled_deleted_category ON product (is_enabled, is_deleted, category_id)');
        $this->addSql('CREATE INDEX idx_product_slug ON product (slug)');
        $this->addSql('CREATE INDEX idx_category_media_category_usage ON category_media (category_id, media_usage)');
        $this->addSql('ALTER TABLE "order" DROP parcels_confirmed');
        $this->addSql('ALTER TABLE "order" DROP parcels_confirmed_at');
        $this->addSql('ALTER TABLE "order" DROP labels_invalidated');
        $this->addSql('ALTER TABLE "order" DROP labels_invalidated_message');
        $this->addSql('ALTER TABLE "order" DROP labels_invalidated_at');
    }
}
