<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260613130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add provider documents and idempotent secure v2 submission records';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'postgresql',
            'This migration can only be executed safely on PostgreSQL.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE provider_document (
                id BIGSERIAL NOT NULL,
                revision_id BIGINT NOT NULL,
                asset_id BIGINT NOT NULL,
                document_type VARCHAR(40) NOT NULL,
                side VARCHAR(20) DEFAULT NULL,
                checksum_sha256 VARCHAR(64) NOT NULL,
                verification_status VARCHAR(32) NOT NULL DEFAULT 'VALID',
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_provider_document_revision
                    FOREIGN KEY (revision_id) REFERENCES provider_application_revision (id) ON DELETE CASCADE,
                CONSTRAINT fk_provider_document_asset
                    FOREIGN KEY (asset_id) REFERENCES uploaded_asset (id) ON DELETE RESTRICT,
                CONSTRAINT chk_provider_document_type
                    CHECK (document_type IN (
                        'IDENTITY_FRONT',
                        'IDENTITY_BACK',
                        'DRIVER_LICENSE_FRONT',
                        'DRIVER_LICENSE_BACK',
                        'VEHICLE_INSURANCE',
                        'VEHICLE_REGISTRATION',
                        'VEHICLE_PHOTO'
                    )),
                CONSTRAINT chk_provider_document_side
                    CHECK (side IS NULL OR side IN ('FRONT', 'BACK')),
                CONSTRAINT chk_provider_document_checksum
                    CHECK (checksum_sha256 ~ '^[0-9a-f]{64}$'),
                CONSTRAINT chk_provider_document_verification
                    CHECK (verification_status IN ('VALID', 'PENDING_SCAN', 'REJECTED'))
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_provider_document_asset ON provider_document (asset_id)');
        $this->addSql('CREATE INDEX idx_provider_document_revision_type ON provider_document (revision_id, document_type)');

        $this->addSql(<<<'SQL'
            CREATE TABLE provider_idempotency_record (
                id BIGSERIAL NOT NULL,
                user_id BIGINT NOT NULL,
                operation VARCHAR(80) NOT NULL,
                idempotency_key VARCHAR(120) NOT NULL,
                request_hash VARCHAR(64) NOT NULL,
                response_status INT NOT NULL,
                response_body JSONB NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_provider_idempotency_user
                    FOREIGN KEY (user_id) REFERENCES user_account (id) ON DELETE CASCADE,
                CONSTRAINT chk_provider_idempotency_hash
                    CHECK (request_hash ~ '^[0-9a-f]{64}$'),
                CONSTRAINT chk_provider_idempotency_response
                    CHECK (jsonb_typeof(response_body) = 'object')
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_provider_idempotency_key
            ON provider_idempotency_record (user_id, operation, idempotency_key)
            SQL);
        $this->addSql('CREATE INDEX idx_provider_idempotency_expiration ON provider_idempotency_record (expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE provider_idempotency_record');
        $this->addSql('DROP TABLE provider_document');
    }
}
