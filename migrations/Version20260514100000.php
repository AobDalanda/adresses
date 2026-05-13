<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260514100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create secure QR code tables for existing addresses';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE address_qrcodes (
                id BIGSERIAL PRIMARY KEY,
                address_id BIGINT NOT NULL REFERENCES address(id) ON DELETE CASCADE,
                token VARCHAR(80) NOT NULL,
                is_active BOOLEAN DEFAULT true NOT NULL,
                expires_at TIMESTAMP DEFAULT NULL,
                max_scans INT DEFAULT NULL,
                current_scans INT DEFAULT 0 NOT NULL,
                allowed_user_id BIGINT DEFAULT NULL REFERENCES user_account(id) ON DELETE SET NULL,
                created_by BIGINT NOT NULL REFERENCES user_account(id) ON DELETE CASCADE,
                created_at TIMESTAMP DEFAULT now() NOT NULL,
                updated_at TIMESTAMP DEFAULT now() NOT NULL,
                revoked_at TIMESTAMP DEFAULT NULL
            )
        ");
        $this->addSql('CREATE UNIQUE INDEX uniq_address_qrcodes_token ON address_qrcodes (token)');
        $this->addSql('CREATE INDEX idx_address_qrcodes_address_id ON address_qrcodes (address_id)');
        $this->addSql('CREATE INDEX idx_address_qrcodes_created_by ON address_qrcodes (created_by)');
        $this->addSql('CREATE INDEX idx_address_qrcodes_allowed_user_id ON address_qrcodes (allowed_user_id)');

        $this->addSql("
            CREATE TABLE qr_scan_logs (
                id BIGSERIAL PRIMARY KEY,
                token VARCHAR(80) DEFAULT NULL,
                ip VARCHAR(45) DEFAULT NULL,
                user_agent TEXT DEFAULT NULL,
                scanned_at TIMESTAMP NOT NULL,
                status VARCHAR(32) NOT NULL,
                user_id BIGINT DEFAULT NULL REFERENCES user_account(id) ON DELETE SET NULL,
                device VARCHAR(50) DEFAULT NULL,
                country VARCHAR(100) DEFAULT NULL,
                city VARCHAR(100) DEFAULT NULL,
                latitude DOUBLE PRECISION DEFAULT NULL,
                longitude DOUBLE PRECISION DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT now() NOT NULL,
                CONSTRAINT chk_qr_scan_logs_status CHECK (
                    status IN ('success', 'expired', 'blocked', 'invalid', 'rate_limited', 'brute_force_detected')
                )
            )
        ");
        $this->addSql('CREATE INDEX idx_qr_scan_logs_token ON qr_scan_logs (token)');
        $this->addSql('CREATE INDEX idx_qr_scan_logs_scanned_at ON qr_scan_logs (scanned_at)');
        $this->addSql('CREATE INDEX idx_qr_scan_logs_user_id ON qr_scan_logs (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS qr_scan_logs');
        $this->addSql('DROP TABLE IF EXISTS address_qrcodes');
    }
}
