<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260623100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prevent duplicate active push tokens for the same mobile device';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'postgresql',
            'This migration can only be executed safely on PostgreSQL.',
        );

        $this->addSql(<<<'SQL'
            WITH ranked AS (
                SELECT
                    id,
                    ROW_NUMBER() OVER (
                        PARTITION BY user_id, platform, device_id
                        ORDER BY updated_at DESC, id DESC
                    ) AS row_number
                FROM user_push_device
                WHERE enabled = TRUE
                  AND device_id IS NOT NULL
            )
            UPDATE user_push_device device
            SET enabled = FALSE,
                updated_at = now()
            FROM ranked
            WHERE device.id = ranked.id
              AND ranked.row_number > 1
            SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_user_push_device_active_device
            ON user_push_device (user_id, platform, device_id)
            WHERE enabled = TRUE
              AND device_id IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_user_push_device_active_device');
    }
}
