<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260613140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add durable outbox retries and automatic provider checks';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'postgresql',
            'This migration can only be executed safely on PostgreSQL.',
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE outbox_event
            ADD next_attempt_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            ADD failed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            ADD processing_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            ADD processing_token UUID DEFAULT NULL
            SQL);
        $this->addSql('DROP INDEX idx_outbox_event_unpublished');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_outbox_event_dispatchable
            ON outbox_event (COALESCE(next_attempt_at, occurred_at), occurred_at, id)
            WHERE published_at IS NULL AND failed_at IS NULL
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE provider_automatic_check (
                id BIGSERIAL NOT NULL,
                application_id BIGINT NOT NULL,
                revision_id BIGINT NOT NULL,
                check_type VARCHAR(60) NOT NULL,
                status VARCHAR(20) NOT NULL,
                score NUMERIC(5, 4) DEFAULT NULL,
                details JSONB NOT NULL,
                engine_version VARCHAR(40) NOT NULL,
                started_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                completed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_provider_automatic_check_application
                    FOREIGN KEY (application_id) REFERENCES provider_application (id) ON DELETE CASCADE,
                CONSTRAINT fk_provider_automatic_check_revision
                    FOREIGN KEY (revision_id) REFERENCES provider_application_revision (id) ON DELETE CASCADE,
                CONSTRAINT chk_provider_automatic_check_type
                    CHECK (check_type IN ('REQUIRED_DOCUMENTS', 'DOCUMENT_INTEGRITY')),
                CONSTRAINT chk_provider_automatic_check_status
                    CHECK (status IN ('PENDING', 'RUNNING', 'PASSED', 'WARNING', 'FAILED', 'ERROR')),
                CONSTRAINT chk_provider_automatic_check_score
                    CHECK (score IS NULL OR (score >= 0 AND score <= 1)),
                CONSTRAINT chk_provider_automatic_check_details
                    CHECK (jsonb_typeof(details) = 'object')
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_provider_automatic_check_revision_type
            ON provider_automatic_check (revision_id, check_type)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_provider_automatic_check_application_status
            ON provider_automatic_check (application_id, status, updated_at)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE provider_automatic_check');
        $this->addSql('DROP INDEX idx_outbox_event_dispatchable');
        $this->addSql('CREATE INDEX idx_outbox_event_unpublished ON outbox_event (occurred_at, id) WHERE published_at IS NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE outbox_event
            DROP next_attempt_at,
            DROP failed_at,
            DROP processing_at,
            DROP processing_token
            SQL);
    }
}
