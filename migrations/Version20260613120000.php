<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260613120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add secure provider upload sessions and owned uploaded assets';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'postgresql',
            'This migration can only be executed safely on PostgreSQL.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE upload_session (
                id BIGSERIAL NOT NULL,
                public_id UUID NOT NULL,
                user_id BIGINT NOT NULL,
                provider_application_id BIGINT DEFAULT NULL,
                purpose VARCHAR(40) NOT NULL,
                allowed_categories JSONB NOT NULL,
                max_files INT NOT NULL,
                max_bytes BIGINT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
                expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                nonce UUID NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_upload_session_user
                    FOREIGN KEY (user_id) REFERENCES user_account (id) ON DELETE CASCADE,
                CONSTRAINT fk_upload_session_application
                    FOREIGN KEY (provider_application_id) REFERENCES provider_application (id) ON DELETE CASCADE,
                CONSTRAINT chk_upload_session_purpose
                    CHECK (purpose = 'PROVIDER_APPLICATION'),
                CONSTRAINT chk_upload_session_categories
                    CHECK (jsonb_typeof(allowed_categories) = 'array' AND jsonb_array_length(allowed_categories) > 0),
                CONSTRAINT chk_upload_session_limits
                    CHECK (max_files > 0 AND max_bytes > 0),
                CONSTRAINT chk_upload_session_status
                    CHECK (status IN ('OPEN', 'COMPLETED', 'EXPIRED', 'REVOKED'))
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_upload_session_public_id ON upload_session (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_upload_session_nonce ON upload_session (nonce)');
        $this->addSql('CREATE INDEX idx_upload_session_user_status ON upload_session (user_id, status, expires_at)');
        $this->addSql('CREATE INDEX idx_upload_session_expiration ON upload_session (expires_at) WHERE status = \'OPEN\'');

        $this->addSql(<<<'SQL'
            CREATE TABLE uploaded_asset (
                id BIGSERIAL NOT NULL,
                public_id UUID NOT NULL,
                session_id BIGINT NOT NULL,
                category VARCHAR(40) NOT NULL,
                bucket VARCHAR(120) NOT NULL,
                object_key VARCHAR(500) NOT NULL,
                mime_type VARCHAR(120) NOT NULL,
                extension VARCHAR(20) NOT NULL,
                size_bytes BIGINT NOT NULL,
                checksum_sha256 VARCHAR(64) NOT NULL,
                validation_status VARCHAR(20) NOT NULL DEFAULT 'VALID',
                consumed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_uploaded_asset_session
                    FOREIGN KEY (session_id) REFERENCES upload_session (id) ON DELETE CASCADE,
                CONSTRAINT chk_uploaded_asset_size
                    CHECK (size_bytes > 0),
                CONSTRAINT chk_uploaded_asset_checksum
                    CHECK (checksum_sha256 ~ '^[0-9a-f]{64}$'),
                CONSTRAINT chk_uploaded_asset_validation
                    CHECK (validation_status IN ('VALID', 'PENDING_SCAN', 'REJECTED'))
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_uploaded_asset_public_id ON uploaded_asset (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_uploaded_asset_storage ON uploaded_asset (bucket, object_key)');
        $this->addSql('CREATE INDEX idx_uploaded_asset_session ON uploaded_asset (session_id, created_at, id)');
        $this->addSql('CREATE INDEX idx_uploaded_asset_unconsumed ON uploaded_asset (session_id, category) WHERE consumed_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE uploaded_asset');
        $this->addSql('DROP TABLE upload_session');
    }
}
