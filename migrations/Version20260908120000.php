<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Vente de livres numériques (v1) :
 *  - product.product_type ('physical' par défaut) + fichier PDF source
 *    (public_id Cloudinary, nom, taille)
 *  - order_item.product_type : snapshot du type au moment de la commande
 *  - table digital_download : droit de téléchargement par ligne de commande
 *  - store_settings.ebook_download_validity_days
 *
 * Toutes les colonnes sur les tables existantes sont additives et ont une
 * valeur par défaut : les produits et commandes existants restent 'physical'.
 */
final class Version20260908120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vente de livres numériques : product.product_type + fichier, order_item.product_type, table digital_download, store_settings.ebook_download_validity_days';
    }

    public function up(Schema $schema): void
    {
        // --- product ---
        $this->addSql("ALTER TABLE product ADD product_type VARCHAR(20) DEFAULT 'physical' NOT NULL");
        $this->addSql('ALTER TABLE product ADD digital_file_public_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD digital_file_original_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD digital_file_size_bytes BIGINT DEFAULT NULL');

        // --- order_item ---
        $this->addSql('ALTER TABLE order_item ADD product_type VARCHAR(20) DEFAULT NULL');

        // --- store_settings ---
        $this->addSql('ALTER TABLE store_settings ADD ebook_download_validity_days INT DEFAULT 90');

        // --- digital_download ---
        $this->addSql(<<<'SQL'
            CREATE TABLE digital_download (
                id UUID NOT NULL,
                order_item_id UUID NOT NULL,
                customer_order_id UUID NOT NULL,
                product_id UUID DEFAULT NULL,
                email VARCHAR(180) NOT NULL,
                token VARCHAR(64) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                max_downloads INT DEFAULT 1 NOT NULL,
                download_count INT DEFAULT 0 NOT NULL,
                first_downloaded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                last_downloaded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                email_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                delivered_file_public_id VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_digital_download_token ON digital_download (token)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_digital_download_order_item ON digital_download (order_item_id)');
        $this->addSql('CREATE INDEX idx_digital_download_token ON digital_download (token)');
        $this->addSql('CREATE INDEX IDX_digital_download_order ON digital_download (customer_order_id)');
        $this->addSql('COMMENT ON COLUMN digital_download.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN digital_download.expires_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN digital_download.first_downloaded_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN digital_download.last_downloaded_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN digital_download.revoked_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE digital_download ADD CONSTRAINT FK_digital_download_order_item FOREIGN KEY (order_item_id) REFERENCES order_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE digital_download ADD CONSTRAINT FK_digital_download_order FOREIGN KEY (customer_order_id) REFERENCES "order" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE digital_download ADD CONSTRAINT FK_digital_download_product FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE digital_download');
        $this->addSql('ALTER TABLE product DROP product_type');
        $this->addSql('ALTER TABLE product DROP digital_file_public_id');
        $this->addSql('ALTER TABLE product DROP digital_file_original_name');
        $this->addSql('ALTER TABLE product DROP digital_file_size_bytes');
        $this->addSql('ALTER TABLE order_item DROP product_type');
        $this->addSql('ALTER TABLE store_settings DROP ebook_download_validity_days');
    }
}
