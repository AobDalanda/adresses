<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260510090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add profile photo storage to user accounts and pending registrations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_account ADD profile_photo_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE pending_user_registration ADD profile_photo_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pending_user_registration DROP profile_photo_path');
        $this->addSql('ALTER TABLE user_account DROP profile_photo_path');
    }
}
