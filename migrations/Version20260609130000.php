<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260609130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the canonical provider application, decision, authorization, and outbox schema';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'postgresql',
            'This migration can only be executed safely on PostgreSQL.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE provider_application (
                id BIGSERIAL NOT NULL,
                public_id UUID NOT NULL,
                provider_profile_id BIGINT NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'DRAFT',
                current_revision_id BIGINT DEFAULT NULL,
                approved_revision_id BIGINT DEFAULT NULL,
                legacy_driver_application_id BIGINT DEFAULT NULL,
                submitted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                decided_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                lock_version INT NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                CONSTRAINT fk_provider_application_profile
                    FOREIGN KEY (provider_profile_id) REFERENCES provider_profile (id) ON DELETE CASCADE,
                CONSTRAINT chk_provider_application_status
                    CHECK (status IN (
                        'DRAFT',
                        'SUBMITTED',
                        'AUTO_CHECK',
                        'UNDER_REVIEW',
                        'CORRECTION_REQUIRED',
                        'RESUBMITTED',
                        'APPROVED',
                        'REJECTED'
                    )),
                CONSTRAINT chk_provider_application_lock_version
                    CHECK (lock_version > 0)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_provider_application_public_id ON provider_application (public_id)');
        $this->addSql('CREATE INDEX idx_provider_application_profile_status ON provider_application (provider_profile_id, status)');
        $this->addSql('CREATE INDEX idx_provider_application_status_updated ON provider_application (status, updated_at, id)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_provider_application_open_profile
            ON provider_application (provider_profile_id)
            WHERE status IN (
                'DRAFT',
                'SUBMITTED',
                'AUTO_CHECK',
                'UNDER_REVIEW',
                'CORRECTION_REQUIRED',
                'RESUBMITTED'
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_provider_application_legacy
            ON provider_application (legacy_driver_application_id)
            WHERE legacy_driver_application_id IS NOT NULL
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE provider_application_revision (
                id BIGSERIAL NOT NULL,
                application_id BIGINT NOT NULL,
                version INT NOT NULL,
                activities JSONB NOT NULL,
                profile_data JSONB NOT NULL,
                submitted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                supersedes_revision_id BIGINT DEFAULT NULL,
                created_by BIGINT NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_provider_revision_application
                    FOREIGN KEY (application_id) REFERENCES provider_application (id) ON DELETE CASCADE,
                CONSTRAINT fk_provider_revision_supersedes
                    FOREIGN KEY (supersedes_revision_id) REFERENCES provider_application_revision (id) ON DELETE SET NULL,
                CONSTRAINT fk_provider_revision_created_by
                    FOREIGN KEY (created_by) REFERENCES user_account (id) ON DELETE RESTRICT,
                CONSTRAINT chk_provider_revision_version
                    CHECK (version > 0),
                CONSTRAINT chk_provider_revision_activities
                    CHECK (
                        jsonb_typeof(activities) = 'array'
                        AND jsonb_array_length(activities) > 0
                        AND activities <@ '["DELIVERY", "PEOPLE_TRANSPORT"]'::jsonb
                    ),
                CONSTRAINT chk_provider_revision_profile_data
                    CHECK (jsonb_typeof(profile_data) = 'object')
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_provider_application_revision_version
            ON provider_application_revision (application_id, version)
            SQL);
        $this->addSql('CREATE INDEX idx_provider_revision_created_by ON provider_application_revision (created_by)');
        $this->addSql('CREATE INDEX idx_provider_revision_supersedes ON provider_application_revision (supersedes_revision_id)');

        $this->addSql(<<<'SQL'
            ALTER TABLE provider_application
            ADD CONSTRAINT fk_provider_application_current_revision
                FOREIGN KEY (current_revision_id) REFERENCES provider_application_revision (id)
                ON DELETE SET NULL DEFERRABLE INITIALLY DEFERRED
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE provider_application
            ADD CONSTRAINT fk_provider_application_approved_revision
                FOREIGN KEY (approved_revision_id) REFERENCES provider_application_revision (id)
                ON DELETE SET NULL DEFERRABLE INITIALLY DEFERRED
            SQL);
        $this->addSql('CREATE INDEX idx_provider_application_current_revision ON provider_application (current_revision_id)');
        $this->addSql('CREATE INDEX idx_provider_application_approved_revision ON provider_application (approved_revision_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE provider_decision_history (
                id BIGSERIAL NOT NULL,
                application_id BIGINT NOT NULL,
                revision_id BIGINT DEFAULT NULL,
                transition VARCHAR(60) NOT NULL,
                old_status VARCHAR(32) DEFAULT NULL,
                new_status VARCHAR(32) DEFAULT NULL,
                actor_type VARCHAR(30) NOT NULL,
                actor_id BIGINT DEFAULT NULL,
                reason_code VARCHAR(80) DEFAULT NULL,
                comment TEXT DEFAULT NULL,
                affected_items JSONB DEFAULT NULL,
                metadata JSONB DEFAULT NULL,
                occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                correlation_id UUID NOT NULL,
                causation_id UUID DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_provider_decision_application
                    FOREIGN KEY (application_id) REFERENCES provider_application (id) ON DELETE CASCADE,
                CONSTRAINT fk_provider_decision_revision
                    FOREIGN KEY (revision_id) REFERENCES provider_application_revision (id) ON DELETE SET NULL,
                CONSTRAINT chk_provider_decision_old_status
                    CHECK (old_status IS NULL OR old_status IN (
                        'DRAFT',
                        'SUBMITTED',
                        'AUTO_CHECK',
                        'UNDER_REVIEW',
                        'CORRECTION_REQUIRED',
                        'RESUBMITTED',
                        'APPROVED',
                        'REJECTED'
                    )),
                CONSTRAINT chk_provider_decision_new_status
                    CHECK (new_status IS NULL OR new_status IN (
                        'DRAFT',
                        'SUBMITTED',
                        'AUTO_CHECK',
                        'UNDER_REVIEW',
                        'CORRECTION_REQUIRED',
                        'RESUBMITTED',
                        'APPROVED',
                        'REJECTED'
                    )),
                CONSTRAINT chk_provider_decision_affected_items
                    CHECK (affected_items IS NULL OR jsonb_typeof(affected_items) = 'array'),
                CONSTRAINT chk_provider_decision_metadata
                    CHECK (metadata IS NULL OR jsonb_typeof(metadata) = 'object')
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_provider_decision_application_time
            ON provider_decision_history (application_id, occurred_at, id)
            SQL);
        $this->addSql('CREATE INDEX idx_provider_decision_revision ON provider_decision_history (revision_id)');
        $this->addSql('CREATE INDEX idx_provider_decision_correlation ON provider_decision_history (correlation_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE provider_authorization (
                id BIGSERIAL NOT NULL,
                provider_profile_id BIGINT NOT NULL,
                source_application_id BIGINT DEFAULT NULL,
                source_revision_id BIGINT DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'INACTIVE',
                can_deliver BOOLEAN NOT NULL DEFAULT FALSE,
                can_transport_people BOOLEAN NOT NULL DEFAULT FALSE,
                suspension_reason_code VARCHAR(80) DEFAULT NULL,
                suspended_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                suspended_by BIGINT DEFAULT NULL,
                reactivated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                reactivated_by BIGINT DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                lock_version INT NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                CONSTRAINT fk_provider_authorization_profile
                    FOREIGN KEY (provider_profile_id) REFERENCES provider_profile (id) ON DELETE CASCADE,
                CONSTRAINT fk_provider_authorization_application
                    FOREIGN KEY (source_application_id) REFERENCES provider_application (id) ON DELETE SET NULL,
                CONSTRAINT fk_provider_authorization_revision
                    FOREIGN KEY (source_revision_id) REFERENCES provider_application_revision (id) ON DELETE SET NULL,
                CONSTRAINT fk_provider_authorization_suspended_by
                    FOREIGN KEY (suspended_by) REFERENCES user_account (id) ON DELETE SET NULL,
                CONSTRAINT fk_provider_authorization_reactivated_by
                    FOREIGN KEY (reactivated_by) REFERENCES user_account (id) ON DELETE SET NULL,
                CONSTRAINT chk_provider_authorization_status
                    CHECK (status IN ('INACTIVE', 'ACTIVE', 'SUSPENDED')),
                CONSTRAINT chk_provider_authorization_lock_version
                    CHECK (lock_version > 0)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_provider_authorization_profile
            ON provider_authorization (provider_profile_id)
            SQL);
        $this->addSql('CREATE INDEX idx_provider_authorization_status ON provider_authorization (status, updated_at, id)');
        $this->addSql('CREATE INDEX idx_provider_authorization_application ON provider_authorization (source_application_id)');
        $this->addSql('CREATE INDEX idx_provider_authorization_revision ON provider_authorization (source_revision_id)');
        $this->addSql('CREATE INDEX idx_provider_authorization_suspended_by ON provider_authorization (suspended_by)');
        $this->addSql('CREATE INDEX idx_provider_authorization_reactivated_by ON provider_authorization (reactivated_by)');

        $this->addSql(<<<'SQL'
            CREATE TABLE outbox_event (
                id UUID NOT NULL,
                aggregate_type VARCHAR(80) NOT NULL,
                aggregate_id VARCHAR(64) NOT NULL,
                event_name VARCHAR(120) NOT NULL,
                payload JSONB NOT NULL,
                occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                published_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                attempts INT NOT NULL DEFAULT 0,
                last_error TEXT DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT chk_outbox_event_payload
                    CHECK (jsonb_typeof(payload) = 'object'),
                CONSTRAINT chk_outbox_event_attempts
                    CHECK (attempts >= 0)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_outbox_event_unpublished
            ON outbox_event (occurred_at, id)
            WHERE published_at IS NULL
            SQL);
        $this->addSql('CREATE INDEX idx_outbox_event_aggregate ON outbox_event (aggregate_type, aggregate_id, occurred_at)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'postgresql',
            'This migration can only be executed safely on PostgreSQL.',
        );

        $this->addSql('DROP TABLE outbox_event');
        $this->addSql('DROP TABLE provider_authorization');
        $this->addSql('DROP TABLE provider_decision_history');
        $this->addSql('ALTER TABLE provider_application DROP CONSTRAINT fk_provider_application_current_revision');
        $this->addSql('ALTER TABLE provider_application DROP CONSTRAINT fk_provider_application_approved_revision');
        $this->addSql('DROP TABLE provider_application_revision');
        $this->addSql('DROP TABLE provider_application');
    }
}
