<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260619140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow upload sessions for delivery order package photo uploads';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'postgresql',
            'This migration can only be executed safely on PostgreSQL.',
        );

        $this->addSql('ALTER TABLE upload_session DROP CONSTRAINT chk_upload_session_purpose');
        $this->addSql(<<<'SQL'
            ALTER TABLE upload_session
            ADD CONSTRAINT chk_upload_session_purpose
            CHECK (purpose IN ('PROVIDER_APPLICATION', 'DELIVERY_ORDER'))
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE upload_session DROP CONSTRAINT chk_upload_session_purpose');
        $this->addSql(<<<'SQL'
            ALTER TABLE upload_session
            ADD CONSTRAINT chk_upload_session_purpose
            CHECK (purpose = 'PROVIDER_APPLICATION')
            SQL);
    }
}
