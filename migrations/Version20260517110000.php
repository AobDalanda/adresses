<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260517110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add identity document number to user accounts and pending registrations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_account ADD identity_document_number VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE pending_user_registration ADD identity_document_number VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pending_user_registration DROP identity_document_number');
        $this->addSql('ALTER TABLE user_account DROP identity_document_number');
    }
}
