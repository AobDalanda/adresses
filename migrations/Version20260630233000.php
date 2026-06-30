<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260630233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow administrators to deactivate mobile client accounts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_account ADD enabled BOOLEAN DEFAULT TRUE NOT NULL');
        $this->addSql('CREATE INDEX idx_user_account_enabled ON user_account (enabled, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_user_account_enabled');
        $this->addSql('ALTER TABLE user_account DROP enabled');
    }
}
