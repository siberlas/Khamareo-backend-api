<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 *  - store_settings.apply_surcharges_france : quand faux (défaut), la France
 *    métropolitaine est facturée au tarif de base (port net) — aucune
 *    surcharge Colissimo (CAE, SMIC, décarbonation, TVA). Les autres
 *    destinations restent inchangées.
 *  - product.container_empty_weight_grams : poids à vide du contenant du
 *    produit (sachet, flacon…), ajouté au poids du produit dans le calcul
 *    du poids total expédié.
 */
final class Version20260910130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'store_settings.apply_surcharges_france + product.container_empty_weight_grams';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE store_settings ADD apply_surcharges_france BOOLEAN DEFAULT false NOT NULL");
        $this->addSql('ALTER TABLE product ADD container_empty_weight_grams INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE store_settings DROP apply_surcharges_france');
        $this->addSql('ALTER TABLE product DROP container_empty_weight_grams');
    }
}
