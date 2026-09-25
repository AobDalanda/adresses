<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add explicit driver availability and heartbeat state';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE driver_availability (
                driver_id BIGINT NOT NULL,
                is_online BOOLEAN DEFAULT FALSE NOT NULL,
                changed_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                last_heartbeat_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY(driver_id),
                CONSTRAINT fk_driver_availability_driver
                    FOREIGN KEY (driver_id) REFERENCES user_account (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_driver_availability_online
            ON driver_availability (last_heartbeat_at, driver_id)
            WHERE is_online = TRUE
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE driver_availability');
    }
}
