<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260630170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add rating to blog_comment, add average_rating and comment_count to blog_post';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blog_comment ADD COLUMN rating SMALLINT NOT NULL DEFAULT 5');
        $this->addSql('ALTER TABLE blog_post ADD COLUMN average_rating FLOAT DEFAULT NULL');
        $this->addSql('ALTER TABLE blog_post ADD COLUMN comment_count INT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blog_comment DROP COLUMN rating');
        $this->addSql('ALTER TABLE blog_post DROP COLUMN average_rating');
        $this->addSql('ALTER TABLE blog_post DROP COLUMN comment_count');
    }
}
