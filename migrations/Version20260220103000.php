<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260220103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add delivery_slip_pdf_path on parcel for per-package delivery note storage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parcel ADD delivery_slip_pdf_path TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parcel DROP delivery_slip_pdf_path');
    }
}
