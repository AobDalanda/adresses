<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track the active mobile device per user account';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_account ADD active_mobile_device_id VARCHAR(160) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_user_account_active_mobile_device ON user_account (active_mobile_device_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_user_account_active_mobile_device');
        $this->addSql('ALTER TABLE user_account DROP active_mobile_device_id');
    }
}
