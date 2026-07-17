<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Registre des cron jobs pilotables depuis l'admin (activer/désactiver,
 * déclenchement manuel, dernier statut) — exécutés par une seule entrée
 * crontab système (app:cron-dispatch) plutôt qu'une ligne par job.
 *
 * Seed initial : les 2 crons email existants — cart:reminder (déjà planifié
 * en prod, migré vers ce système) et app:notify-stock-alerts (existait dans
 * le code mais n'était jamais planifié).
 */
final class Version20260717090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create cron_job table and seed existing email cron commands';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cron_job (id SERIAL NOT NULL, key VARCHAR(100) NOT NULL, label VARCHAR(150) NOT NULL, description TEXT DEFAULT NULL, command_name VARCHAR(100) NOT NULL, cron_expression VARCHAR(50) NOT NULL, enabled BOOLEAN NOT NULL, last_run_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_run_status VARCHAR(20) DEFAULT NULL, last_run_summary TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CRON_JOB_KEY ON cron_job (key)');

        $this->addSql("INSERT INTO cron_job (key, label, description, command_name, cron_expression, enabled, created_at) VALUES ('cart_reminder', 'Relance panier abandonné', 'Envoie un email de rappel aux clients (invités et connectés) ayant un panier non validé.', 'cart:reminder', '0 10 * * *', true, now())");
        $this->addSql("INSERT INTO cron_job (key, label, description, command_name, cron_expression, enabled, created_at) VALUES ('stock_alert_notify', 'Alerte retour en stock', 'Notifie les clients inscrits sur liste d''attente quand un produit repasse en stock.', 'app:notify-stock-alerts', '0 * * * *', true, now())");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cron_job');
    }
}
