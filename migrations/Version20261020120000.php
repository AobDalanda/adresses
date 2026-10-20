<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add soft deletion support for addresses retained by delivery history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE address ADD deleted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_address_deleted_at ON address (deleted_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_address_deleted_at');
        $this->addSql('ALTER TABLE address DROP deleted_at');
    }
}
