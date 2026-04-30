<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260103190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add subscriptions, API clients, OTP, usage, and payment events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE subscription_plan (
                id BIGSERIAL PRIMARY KEY,
                code VARCHAR(30) UNIQUE NOT NULL,
                name VARCHAR(100) NOT NULL,
                owner_type VARCHAR(20) NOT NULL,
                price_cents INT NOT NULL,
                currency VARCHAR(5) NOT NULL,
                quota_create INT,
                quota_lookup INT,
                created_at TIMESTAMP DEFAULT now()
            )
        ");

        $this->addSql("
            CREATE TABLE subscription (
                id BIGSERIAL PRIMARY KEY,
                owner_type VARCHAR(20) NOT NULL,
                owner_id BIGINT NOT NULL,
                plan_id BIGINT REFERENCES subscription_plan(id),
                status VARCHAR(20) NOT NULL,
                current_period_start TIMESTAMP NOT NULL,
                current_period_end TIMESTAMP NOT NULL,
                created_at TIMESTAMP DEFAULT now()
            )
        ");

        $this->addSql("
            CREATE INDEX idx_subscription_owner
            ON subscription (owner_type, owner_id, status)
        ");

        $this->addSql("
            CREATE TABLE api_client (
                id BIGSERIAL PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                client_id VARCHAR(50) UNIQUE NOT NULL,
                client_secret_hash VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT now()
            )
        ");

        $this->addSql("
            CREATE TABLE api_usage (
                id BIGSERIAL PRIMARY KEY,
                client_id BIGINT REFERENCES api_client(id) ON DELETE CASCADE,
                period_start DATE NOT NULL,
                period_end DATE NOT NULL,
                count INT NOT NULL DEFAULT 0,
                UNIQUE (client_id, period_start, period_end)
            )
        ");

        $this->addSql("
            CREATE TABLE otp_request (
                id BIGSERIAL PRIMARY KEY,
                phone VARCHAR(20) NOT NULL,
                otp_hash VARCHAR(255) NOT NULL,
                status VARCHAR(20) NOT NULL,
                channel VARCHAR(20) NOT NULL,
                expires_at TIMESTAMP NOT NULL,
                verified_at TIMESTAMP,
                created_at TIMESTAMP DEFAULT now()
            )
        ");

        $this->addSql("
            CREATE INDEX idx_otp_request_phone
            ON otp_request (phone, status)
        ");

        $this->addSql("
            CREATE TABLE payment_event (
                id BIGSERIAL PRIMARY KEY,
                provider VARCHAR(20) NOT NULL,
                provider_ref VARCHAR(100),
                status VARCHAR(20) NOT NULL,
                amount_cents INT NOT NULL,
                currency VARCHAR(5) NOT NULL,
                owner_type VARCHAR(20) NOT NULL,
                owner_id BIGINT NOT NULL,
                plan_id BIGINT REFERENCES subscription_plan(id),
                payload JSONB,
                created_at TIMESTAMP DEFAULT now()
            )
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS payment_event');
        $this->addSql('DROP TABLE IF EXISTS otp_request');
        $this->addSql('DROP TABLE IF EXISTS api_usage');
        $this->addSql('DROP TABLE IF EXISTS api_client');
        $this->addSql('DROP TABLE IF EXISTS subscription');
        $this->addSql('DROP TABLE IF EXISTS subscription_plan');
    }
}
