<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260517103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email to pending user registrations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pending_user_registration ADD email VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pending_user_registration DROP email');
    }
}
