<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260227100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add review moderation fields: isVerified, isPurchaseVerified, adminReply, adminRepliedAt';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE review ADD is_verified BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE review ADD is_purchase_verified BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE review ADD admin_reply TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE review ADD admin_replied_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN review.admin_replied_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE review DROP is_verified');
        $this->addSql('ALTER TABLE review DROP is_purchase_verified');
        $this->addSql('ALTER TABLE review DROP admin_reply');
        $this->addSql('ALTER TABLE review DROP admin_replied_at');
    }
}
