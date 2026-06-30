<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260630160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create blog_comment table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE blog_comment (
                id UUID NOT NULL,
                blog_post_id UUID NOT NULL,
                author_name VARCHAR(100) NOT NULL,
                author_email VARCHAR(180) NOT NULL,
                content TEXT NOT NULL,
                is_approved BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        ");
        $this->addSql('CREATE INDEX idx_blog_comment_post ON blog_comment (blog_post_id)');
        $this->addSql('CREATE INDEX idx_blog_comment_approved ON blog_comment (is_approved)');
        $this->addSql('ALTER TABLE blog_comment ADD CONSTRAINT fk_blog_comment_post FOREIGN KEY (blog_post_id) REFERENCES blog_post (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("COMMENT ON COLUMN blog_comment.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN blog_comment.blog_post_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN blog_comment.created_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blog_comment DROP CONSTRAINT fk_blog_comment_post');
        $this->addSql('DROP TABLE blog_comment');
    }
}
