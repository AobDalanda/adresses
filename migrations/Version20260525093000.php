<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260525093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute token_version sur user_account pour invalider les anciennes sessions JWT mobiles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_account ADD token_version INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_account DROP token_version');
    }
}
