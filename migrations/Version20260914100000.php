<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align mobile session columns with single-device JWT invalidation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_account ALTER COLUMN token_version SET DEFAULT 0');
        $this->addSql('ALTER TABLE user_account ALTER COLUMN active_mobile_device_id TYPE VARCHAR(128)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_account ALTER COLUMN active_mobile_device_id TYPE VARCHAR(160)');
        $this->addSql('ALTER TABLE user_account ALTER COLUMN token_version SET DEFAULT 1');
    }
}
