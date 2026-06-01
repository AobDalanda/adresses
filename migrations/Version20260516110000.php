<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260516110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create SaaS subscription module tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE saas_subscription_plan (
                id BIGSERIAL PRIMARY KEY,
                code VARCHAR(20) NOT NULL UNIQUE,
                name VARCHAR(100) NOT NULL,
                description TEXT DEFAULT NULL,
                price_amount INT NOT NULL,
                currency VARCHAR(5) NOT NULL,
                duration_days INT NOT NULL,
                is_active BOOLEAN NOT NULL DEFAULT true,
                max_addresses INT DEFAULT NULL,
                max_qr_codes INT DEFAULT NULL,
                max_deliveries_per_month INT DEFAULT NULL,
                can_track_delivery BOOLEAN NOT NULL DEFAULT false,
                can_use_custom_qr_code BOOLEAN NOT NULL DEFAULT false,
                can_create_business_address BOOLEAN NOT NULL DEFAULT false,
                created_at TIMESTAMP NOT NULL DEFAULT now(),
                updated_at TIMESTAMP NOT NULL DEFAULT now()
            )
        ");

        $this->addSql("
            CREATE TABLE user_subscription (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL REFERENCES user_account(id) ON DELETE CASCADE,
                plan_id BIGINT NOT NULL REFERENCES saas_subscription_plan(id),
                status VARCHAR(30) NOT NULL,
                started_at TIMESTAMP NOT NULL,
                current_period_start TIMESTAMP NOT NULL,
                current_period_end TIMESTAMP NOT NULL,
                expires_at TIMESTAMP NOT NULL,
                cancelled_at TIMESTAMP DEFAULT NULL,
                auto_renew BOOLEAN NOT NULL DEFAULT false,
                payment_provider VARCHAR(30) DEFAULT NULL,
                provider_subscription_id VARCHAR(120) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT now(),
                updated_at TIMESTAMP NOT NULL DEFAULT now()
            )
        ");
        $this->addSql("CREATE INDEX idx_user_subscription_user ON user_subscription (user_id)");
        $this->addSql("CREATE UNIQUE INDEX uniq_user_subscription_single_active ON user_subscription (user_id) WHERE status IN ('active', 'trialing', 'past_due')");

        $this->addSql("
            CREATE TABLE payment_transaction (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL REFERENCES user_account(id) ON DELETE CASCADE,
                subscription_id BIGINT NOT NULL REFERENCES user_subscription(id) ON DELETE CASCADE,
                provider VARCHAR(30) NOT NULL,
                provider_reference VARCHAR(120) NOT NULL UNIQUE,
                amount INT NOT NULL,
                currency VARCHAR(5) NOT NULL,
                status VARCHAR(30) NOT NULL,
                raw_payload JSONB DEFAULT NULL,
                paid_at TIMESTAMP DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT now(),
                updated_at TIMESTAMP NOT NULL DEFAULT now()
            )
        ");

        $this->addSql("
            CREATE TABLE usage_counter (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL REFERENCES user_account(id) ON DELETE CASCADE,
                period_start TIMESTAMP NOT NULL,
                period_end TIMESTAMP NOT NULL,
                addresses_created INT NOT NULL DEFAULT 0,
                qr_codes_generated INT NOT NULL DEFAULT 0,
                deliveries_created INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT now(),
                updated_at TIMESTAMP NOT NULL DEFAULT now(),
                UNIQUE (user_id, period_start, period_end)
            )
        ");

        $this->addSql("
            CREATE TABLE subscription_event (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL REFERENCES user_account(id) ON DELETE CASCADE,
                subscription_id BIGINT DEFAULT NULL REFERENCES user_subscription(id) ON DELETE SET NULL,
                type VARCHAR(50) NOT NULL,
                old_status VARCHAR(40) DEFAULT NULL,
                new_status VARCHAR(40) DEFAULT NULL,
                metadata JSONB DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT now()
            )
        ");
        $this->addSql("CREATE INDEX idx_subscription_event_user ON subscription_event (user_id)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS subscription_event');
        $this->addSql('DROP TABLE IF EXISTS usage_counter');
        $this->addSql('DROP TABLE IF EXISTS payment_transaction');
        $this->addSql('DROP TABLE IF EXISTS user_subscription');
        $this->addSql('DROP TABLE IF EXISTS saas_subscription_plan');
    }
}
