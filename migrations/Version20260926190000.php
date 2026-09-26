<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Separate requested driver availability from GPS presence and add monotonic versioning';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE driver_availability RENAME COLUMN is_online TO requested_online');
        $this->addSql('ALTER TABLE driver_availability ADD availability_version BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('DROP INDEX idx_driver_availability_online');
        $this->addSql('ALTER TABLE driver_availability DROP last_heartbeat_at');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_driver_availability_requested_online
            ON driver_availability (driver_id)
            WHERE requested_online = TRUE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE driver_availability
            ADD CONSTRAINT chk_driver_availability_version CHECK (availability_version >= 0)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE driver_availability DROP CONSTRAINT chk_driver_availability_version');
        $this->addSql('DROP INDEX idx_driver_availability_requested_online');
        $this->addSql('ALTER TABLE driver_availability ADD last_heartbeat_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE driver_availability DROP availability_version');
        $this->addSql('ALTER TABLE driver_availability RENAME COLUMN requested_online TO is_online');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_driver_availability_online
            ON driver_availability (last_heartbeat_at, driver_id)
            WHERE is_online = TRUE
            SQL);
    }
}
