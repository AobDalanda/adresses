<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260627100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Separate mobile, provider and back-office identities and authentication contexts';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'postgresql',
            'This migration can only be executed safely on PostgreSQL.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE back_office_account (
                user_id BIGINT NOT NULL,
                enabled BOOLEAN NOT NULL DEFAULT TRUE,
                token_version INT NOT NULL DEFAULT 1,
                last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT now(),
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT now(),
                PRIMARY KEY (user_id),
                CONSTRAINT fk_back_office_account_user
                    FOREIGN KEY (user_id) REFERENCES user_account (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_back_office_account_enabled ON back_office_account (enabled, user_id)');
        $this->addSql(<<<'SQL'
            INSERT INTO back_office_account (user_id)
            SELECT DISTINCT account.id
            FROM user_account account
            LEFT JOIN user_account_role role ON role.user_id = account.id
            WHERE account.account_type = 'admin'
               OR role.role IN (
                    'ROLE_ADMIN',
                    'ROLE_PROVIDER_REVIEWER',
                    'ROLE_PROVIDER_APPROVER',
                    'ROLE_PROVIDER_SECURITY_ADMIN'
               )
            ON CONFLICT (user_id) DO NOTHING
            SQL);

        $this->addSql("ALTER TABLE otp_request ADD purpose VARCHAR(30) DEFAULT 'MOBILE_AUTH' NOT NULL");
        $this->addSql(<<<'SQL'
            ALTER TABLE otp_request
            ADD CONSTRAINT chk_otp_request_purpose
            CHECK (purpose IN ('MOBILE_AUTH', 'BACK_OFFICE_AUTH'))
            SQL);
        $this->addSql('DROP INDEX IF EXISTS idx_otp_request_phone');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_otp_request_phone_purpose
            ON otp_request (phone, purpose, status, created_at DESC)
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE user_account account
            SET account_type = 'client'
            WHERE account.account_type = 'provider'
              AND EXISTS (
                  SELECT 1 FROM provider_profile profile WHERE profile.user_id = account.id
              )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE user_account account
            SET account_type = 'provider'
            WHERE EXISTS (
                SELECT 1 FROM provider_profile profile WHERE profile.user_id = account.id
            )
              AND account.account_type = 'client'
            SQL);
        $this->addSql('DROP INDEX idx_otp_request_phone_purpose');
        $this->addSql('ALTER TABLE otp_request DROP CONSTRAINT chk_otp_request_purpose');
        $this->addSql('ALTER TABLE otp_request DROP purpose');
        $this->addSql('CREATE INDEX idx_otp_request_phone ON otp_request (phone, status)');
        $this->addSql('DROP TABLE back_office_account');
    }
}
