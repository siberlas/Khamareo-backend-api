<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251226180714 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE category_media (id UUID NOT NULL, category_id UUID NOT NULL, media_id UUID NOT NULL, media_usage VARCHAR(50) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_821FEE4512469DE2 ON category_media (category_id)');
        $this->addSql('CREATE INDEX IDX_821FEE45EA9FDD75 ON category_media (media_id)');
        $this->addSql('COMMENT ON COLUMN category_media.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN category_media.category_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN category_media.media_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN category_media.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE hero_slide (id UUID NOT NULL, media_id UUID DEFAULT NULL, title_key VARCHAR(255) DEFAULT NULL, subtitle_key VARCHAR(255) DEFAULT NULL, description_key VARCHAR(255) DEFAULT NULL, cta_key VARCHAR(255) DEFAULT NULL, cta_link VARCHAR(500) DEFAULT NULL, display_order INT NOT NULL, is_active BOOLEAN NOT NULL, start_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, end_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_EDD0E1A5EA9FDD75 ON hero_slide (media_id)');
        $this->addSql('COMMENT ON COLUMN hero_slide.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN hero_slide.media_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN hero_slide.start_date IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN hero_slide.end_date IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN hero_slide.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN hero_slide.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE media (id UUID NOT NULL, created_by_id UUID DEFAULT NULL, cloudinary_public_id VARCHAR(255) NOT NULL, cloudinary_asset_id VARCHAR(255) DEFAULT NULL, url TEXT NOT NULL, thumbnail_url TEXT DEFAULT NULL, filename VARCHAR(255) DEFAULT NULL, alt_text VARCHAR(500) DEFAULT NULL, media_type VARCHAR(50) NOT NULL, mime_type VARCHAR(100) DEFAULT NULL, width INT DEFAULT NULL, height INT DEFAULT NULL, file_size INT DEFAULT NULL, tags JSON DEFAULT NULL, folder VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6A2CA10C9E3099DF ON media (cloudinary_public_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6A2CA10C3592C5FB ON media (cloudinary_asset_id)');
        $this->addSql('CREATE INDEX IDX_6A2CA10CB03A8386 ON media (created_by_id)');
        $this->addSql('COMMENT ON COLUMN media.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN media.created_by_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN media.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN media.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE product_media (id UUID NOT NULL, product_id UUID NOT NULL, media_id UUID NOT NULL, display_order INT NOT NULL, is_primary BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_CB70DA504584665A ON product_media (product_id)');
        $this->addSql('CREATE INDEX IDX_CB70DA50EA9FDD75 ON product_media (media_id)');
        $this->addSql('COMMENT ON COLUMN product_media.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN product_media.product_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN product_media.media_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN product_media.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE category_media ADD CONSTRAINT FK_821FEE4512469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE category_media ADD CONSTRAINT FK_821FEE45EA9FDD75 FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE hero_slide ADD CONSTRAINT FK_EDD0E1A5EA9FDD75 FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE media ADD CONSTRAINT FK_6A2CA10CB03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE product_media ADD CONSTRAINT FK_CB70DA504584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE product_media ADD CONSTRAINT FK_CB70DA50EA9FDD75 FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE category_media DROP CONSTRAINT FK_821FEE4512469DE2');
        $this->addSql('ALTER TABLE category_media DROP CONSTRAINT FK_821FEE45EA9FDD75');
        $this->addSql('ALTER TABLE hero_slide DROP CONSTRAINT FK_EDD0E1A5EA9FDD75');
        $this->addSql('ALTER TABLE media DROP CONSTRAINT FK_6A2CA10CB03A8386');
        $this->addSql('ALTER TABLE product_media DROP CONSTRAINT FK_CB70DA504584665A');
        $this->addSql('ALTER TABLE product_media DROP CONSTRAINT FK_CB70DA50EA9FDD75');
        $this->addSql('DROP TABLE category_media');
        $this->addSql('DROP TABLE hero_slide');
        $this->addSql('DROP TABLE media');
        $this->addSql('DROP TABLE product_media');
    }
}
