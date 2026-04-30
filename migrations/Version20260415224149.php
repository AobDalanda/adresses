<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260415224149 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pending mobile user registrations finalized by OTP';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE pending_user_registration (
                id BIGSERIAL PRIMARY KEY,
                phone VARCHAR(20) UNIQUE NOT NULL,
                full_name VARCHAR(100) NOT NULL,
                status VARCHAR(20) NOT NULL,
                otp_verified_at TIMESTAMP,
                created_at TIMESTAMP DEFAULT now()
            )
        ");

        $this->addSql("
            CREATE INDEX idx_pending_user_registration_phone_status
            ON pending_user_registration (phone, status)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS pending_user_registration');
    }
}
