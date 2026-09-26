<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926194500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replay delivery notifications after isolating Mercure and FCM failures';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE outbox_event
            SET attempts = 0,
                last_error = NULL,
                failed_at = NULL,
                next_attempt_at = now(),
                processing_at = NULL,
                processing_token = NULL
            WHERE event_name = 'delivery_order.created'
              AND published_at IS NULL
              AND last_error LIKE '%Failed to send an update%'
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Replaying durable events cannot be safely undone.
    }
}
