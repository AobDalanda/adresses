<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email to user accounts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_account ADD email VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE user_account ADD CONSTRAINT uniq_user_account_email UNIQUE (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_account DROP CONSTRAINT uniq_user_account_email');
        $this->addSql('ALTER TABLE user_account DROP email');
    }
}
