<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260705143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add delivery proof and mission payment settlement fields';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'postgresql',
            'This migration can only be executed safely on PostgreSQL.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE delivery_proof (
                id BIGSERIAL NOT NULL,
                delivery_order_id BIGINT NOT NULL,
                reception_code VARCHAR(20) DEFAULT NULL,
                recipient_name VARCHAR(120) DEFAULT NULL,
                recipient_signature_asset_id BIGINT DEFAULT NULL,
                delivery_photo_asset_id BIGINT DEFAULT NULL,
                signed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                photo_captured_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_delivery_proof_order
                    FOREIGN KEY (delivery_order_id) REFERENCES delivery_order (id) ON DELETE CASCADE,
                CONSTRAINT fk_delivery_proof_signature_asset
                    FOREIGN KEY (recipient_signature_asset_id) REFERENCES uploaded_asset (id) ON DELETE SET NULL,
                CONSTRAINT fk_delivery_proof_photo_asset
                    FOREIGN KEY (delivery_photo_asset_id) REFERENCES uploaded_asset (id) ON DELETE SET NULL,
                CONSTRAINT chk_delivery_proof_reception_code
                    CHECK (reception_code IS NULL OR length(trim(reception_code)) BETWEEN 4 AND 20)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_delivery_proof_order ON delivery_proof (delivery_order_id)');
        $this->addSql("ALTER TABLE delivery_driver_earning ADD settlement_status VARCHAR(20) NOT NULL DEFAULT 'PENDING'");
        $this->addSql("ALTER TABLE delivery_driver_earning ADD settled_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL");
        $this->addSql(<<<'SQL'
            ALTER TABLE delivery_driver_earning
            ADD CONSTRAINT chk_delivery_driver_earning_settlement_status
            CHECK (settlement_status IN ('PENDING', 'PAID'))
            SQL);

        $this->addSql('ALTER TABLE upload_session DROP CONSTRAINT chk_upload_session_purpose');
        $this->addSql(<<<'SQL'
            ALTER TABLE upload_session
            ADD CONSTRAINT chk_upload_session_purpose
            CHECK (purpose IN ('PROVIDER_APPLICATION', 'DELIVERY_ORDER', 'DELIVERY_PROOF'))
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE upload_session DROP CONSTRAINT chk_upload_session_purpose');
        $this->addSql(<<<'SQL'
            ALTER TABLE upload_session
            ADD CONSTRAINT chk_upload_session_purpose
            CHECK (purpose IN ('PROVIDER_APPLICATION', 'DELIVERY_ORDER'))
            SQL);
        $this->addSql('ALTER TABLE delivery_driver_earning DROP CONSTRAINT chk_delivery_driver_earning_settlement_status');
        $this->addSql('ALTER TABLE delivery_driver_earning DROP settled_at');
        $this->addSql('ALTER TABLE delivery_driver_earning DROP settlement_status');
        $this->addSql('DROP TABLE delivery_proof');
    }
}
