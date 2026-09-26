<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replay delivery notifications blocked by the untyped pickup country parameter';
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
              AND last_error LIKE '%SQLSTATE[42P18]%'
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Replaying durable events cannot be safely undone.
    }
}
