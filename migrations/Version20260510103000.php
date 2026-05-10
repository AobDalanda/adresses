<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260510103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add account type and document storage to user registrations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE user_account ADD account_type VARCHAR(20) DEFAULT 'client' NOT NULL");
        $this->addSql('ALTER TABLE user_account ADD identity_document_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE user_account ADD driver_license_path VARCHAR(255) DEFAULT NULL');

        $this->addSql("ALTER TABLE pending_user_registration ADD account_type VARCHAR(20) DEFAULT 'client' NOT NULL");
        $this->addSql('ALTER TABLE pending_user_registration ADD identity_document_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE pending_user_registration ADD driver_license_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pending_user_registration DROP driver_license_path');
        $this->addSql('ALTER TABLE pending_user_registration DROP identity_document_path');
        $this->addSql('ALTER TABLE pending_user_registration DROP account_type');

        $this->addSql('ALTER TABLE user_account DROP driver_license_path');
        $this->addSql('ALTER TABLE user_account DROP identity_document_path');
        $this->addSql('ALTER TABLE user_account DROP account_type');
    }
}
