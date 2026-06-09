<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260606150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create provider profiles and normalize legacy account types';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE provider_profile (
                id BIGSERIAL NOT NULL,
                user_id BIGINT NOT NULL,
                can_deliver BOOLEAN NOT NULL DEFAULT false,
                can_transport_people BOOLEAN NOT NULL DEFAULT false,
                validation_status VARCHAR(20) NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT now(),
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT now(),
                PRIMARY KEY(id),
                CONSTRAINT uniq_provider_profile_user UNIQUE (user_id),
                CONSTRAINT fk_provider_profile_user
                    FOREIGN KEY (user_id) REFERENCES user_account (id) ON DELETE CASCADE,
                CONSTRAINT chk_provider_profile_activity
                    CHECK (can_deliver OR can_transport_people),
                CONSTRAINT chk_provider_profile_validation_status
                    CHECK (validation_status IN ('pending', 'approved', 'rejected', 'suspended'))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_provider_profile_validation_status ON provider_profile (validation_status)');
        $this->addSql('CREATE INDEX idx_provider_profile_can_deliver ON provider_profile (can_deliver) WHERE can_deliver = true');
        $this->addSql('CREATE INDEX idx_provider_profile_can_transport_people ON provider_profile (can_transport_people) WHERE can_transport_people = true');

        $this->addSql(<<<'SQL'
            INSERT INTO provider_profile (user_id, can_deliver, can_transport_people, validation_status)
            SELECT
                id,
                lower(account_type) IN ('livreur', 'driver', 'driver_transport', 'both'),
                lower(account_type) IN ('transporteur', 'transporter', 'driver_transport', 'both'),
                'approved'
            FROM user_account
            WHERE lower(account_type) IN (
                'livreur',
                'driver',
                'transporteur',
                'transporter',
                'driver_transport',
                'both'
            )
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE user_account
            SET account_type = 'provider'
            WHERE lower(account_type) IN (
                'livreur',
                'driver',
                'transporteur',
                'transporter',
                'driver_transport',
                'both'
            )
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE user_account
            ADD CONSTRAINT chk_user_account_type
            CHECK (account_type IN ('client', 'provider', 'admin'))
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_account DROP CONSTRAINT IF EXISTS chk_user_account_type');
        $this->addSql(<<<'SQL'
            UPDATE user_account AS account
            SET account_type = CASE
                WHEN profile.can_deliver AND profile.can_transport_people THEN 'driver_transport'
                WHEN profile.can_deliver THEN 'driver'
                WHEN profile.can_transport_people THEN 'transporter'
                ELSE 'client'
            END
            FROM provider_profile AS profile
            WHERE profile.user_id = account.id
            SQL);
        $this->addSql('DROP TABLE provider_profile');
    }
}
