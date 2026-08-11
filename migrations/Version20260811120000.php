<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute les dimensions produit (L×l×h) et les champs douane (code SH,
 * pays d'origine), utilisés par le moteur d'estimation checkout et la
 * déclaration CN23 Colissimo (jusqu'ici en dur : HS_CODE_PLANTS, 'FR').
 */
final class Version20260811120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute product.length_cm, width_cm, height_cm, code_sh, pays_origine';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD length_cm INT DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD width_cm INT DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD height_cm INT DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD code_sh VARCHAR(6) DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD pays_origine VARCHAR(2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP length_cm');
        $this->addSql('ALTER TABLE product DROP width_cm');
        $this->addSql('ALTER TABLE product DROP height_cm');
        $this->addSql('ALTER TABLE product DROP code_sh');
        $this->addSql('ALTER TABLE product DROP pays_origine');
    }
}
