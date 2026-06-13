<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260609120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add server-side administrative roles for provider validation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE user_account_role (
                user_id BIGINT NOT NULL,
                role VARCHAR(50) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT now(),
                PRIMARY KEY (user_id, role),
                CONSTRAINT fk_user_account_role_user
                    FOREIGN KEY (user_id) REFERENCES user_account (id) ON DELETE CASCADE,
                CONSTRAINT chk_user_account_role
                    CHECK (role IN (
                        'ROLE_PROVIDER_REVIEWER',
                        'ROLE_PROVIDER_APPROVER',
                        'ROLE_PROVIDER_SECURITY_ADMIN',
                        'ROLE_ADMIN'
                    ))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_user_account_role_role ON user_account_role (role, user_id)');
        $this->addSql(<<<'SQL'
            INSERT INTO user_account_role (user_id, role)
            SELECT id, 'ROLE_ADMIN'
            FROM user_account
            WHERE account_type = 'admin'
            ON CONFLICT (user_id, role) DO NOTHING
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_account_role');
    }
}
