<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260613150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add secure push devices and idempotent in-app notifications';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'postgresql',
            'This migration can only be executed safely on PostgreSQL.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE user_push_device (
                id BIGSERIAL NOT NULL,
                user_id BIGINT NOT NULL,
                token_hash CHAR(64) NOT NULL,
                token TEXT NOT NULL,
                platform VARCHAR(16) NOT NULL,
                device_id VARCHAR(160) DEFAULT NULL,
                enabled BOOLEAN NOT NULL DEFAULT TRUE,
                last_seen_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_user_push_device_user
                    FOREIGN KEY (user_id) REFERENCES user_account (id) ON DELETE CASCADE,
                CONSTRAINT chk_user_push_device_platform
                    CHECK (platform IN ('android', 'ios', 'web'))
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_user_push_device_token_hash ON user_push_device (token_hash)');
        $this->addSql('CREATE INDEX idx_user_push_device_user_enabled ON user_push_device (user_id, enabled)');

        $this->addSql(<<<'SQL'
            CREATE TABLE user_notification (
                id UUID NOT NULL,
                user_id BIGINT NOT NULL,
                source_event_id UUID NOT NULL,
                type VARCHAR(80) NOT NULL,
                title VARCHAR(160) NOT NULL,
                body TEXT NOT NULL,
                data JSONB NOT NULL,
                push_status VARCHAR(20) NOT NULL,
                push_attempts INT NOT NULL DEFAULT 0,
                push_last_error TEXT DEFAULT NULL,
                read_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_user_notification_user
                    FOREIGN KEY (user_id) REFERENCES user_account (id) ON DELETE CASCADE,
                CONSTRAINT fk_user_notification_source_event
                    FOREIGN KEY (source_event_id) REFERENCES outbox_event (id) ON DELETE CASCADE,
                CONSTRAINT chk_user_notification_data
                    CHECK (jsonb_typeof(data) = 'object'),
                CONSTRAINT chk_user_notification_push_status
                    CHECK (push_status IN ('PENDING', 'SENT', 'PARTIAL', 'SKIPPED', 'FAILED')),
                CONSTRAINT chk_user_notification_push_attempts
                    CHECK (push_attempts >= 0)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_user_notification_event_user ON user_notification (source_event_id, user_id)');
        $this->addSql('CREATE INDEX idx_user_notification_inbox ON user_notification (user_id, created_at DESC, id)');
        $this->addSql('CREATE INDEX idx_user_notification_unread ON user_notification (user_id, created_at DESC) WHERE read_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_notification');
        $this->addSql('DROP TABLE user_push_device');
    }
}
