<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260605120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create production driver GPS tracking table with PostGIS indexes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS postgis');
        $this->addSql(<<<'SQL'
            CREATE TABLE driver_location (
                id BIGSERIAL NOT NULL,
                driver_id BIGINT NOT NULL,
                latitude DOUBLE PRECISION NOT NULL,
                longitude DOUBLE PRECISION NOT NULL,
                accuracy DOUBLE PRECISION NOT NULL,
                speed DOUBLE PRECISION DEFAULT NULL,
                heading DOUBLE PRECISION DEFAULT NULL,
                battery_level INT DEFAULT NULL,
                source VARCHAR(30) NOT NULL,
                position geography(POINT, 4326) NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT fk_driver_location_driver
                    FOREIGN KEY (driver_id) REFERENCES user_account (id) ON DELETE CASCADE,
                CONSTRAINT chk_driver_location_latitude CHECK (latitude BETWEEN -90 AND 90),
                CONSTRAINT chk_driver_location_longitude CHECK (longitude BETWEEN -180 AND 180),
                CONSTRAINT chk_driver_location_accuracy CHECK (accuracy >= 0),
                CONSTRAINT chk_driver_location_speed CHECK (speed IS NULL OR speed >= 0),
                CONSTRAINT chk_driver_location_heading CHECK (heading IS NULL OR heading BETWEEN 0 AND 360),
                CONSTRAINT chk_driver_location_battery CHECK (battery_level IS NULL OR battery_level BETWEEN 0 AND 100)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_driver_location_driver ON driver_location (driver_id)');
        $this->addSql('CREATE INDEX idx_driver_location_created_at ON driver_location (created_at)');
        $this->addSql('CREATE INDEX idx_driver_location_driver_created ON driver_location (driver_id, created_at DESC, id DESC)');
        $this->addSql('CREATE INDEX idx_driver_location_position_gist ON driver_location USING GIST (position)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE driver_location');
    }
}
