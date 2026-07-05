<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260705120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add driver earnings and reversible GPS provenance for delivery missions';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'postgresql',
            'This migration can only be executed safely on PostgreSQL.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE delivery_driver_earning (
                id BIGSERIAL NOT NULL,
                delivery_order_id BIGINT NOT NULL,
                estimated_amount NUMERIC(12, 2) DEFAULT NULL,
                final_amount NUMERIC(12, 2) DEFAULT NULL,
                currency VARCHAR(3) NOT NULL,
                calculated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                finalized_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_delivery_driver_earning_order
                    FOREIGN KEY (delivery_order_id) REFERENCES delivery_order (id) ON DELETE CASCADE,
                CONSTRAINT chk_delivery_driver_earning_estimated
                    CHECK (estimated_amount IS NULL OR estimated_amount >= 0),
                CONSTRAINT chk_delivery_driver_earning_final
                    CHECK (final_amount IS NULL OR final_amount >= 0),
                CONSTRAINT chk_delivery_driver_earning_amount
                    CHECK (estimated_amount IS NOT NULL OR final_amount IS NOT NULL)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_delivery_driver_earning_order ON delivery_driver_earning (delivery_order_id)');

        $this->addSql('ALTER TABLE gps_weighted_location ADD accuracy_m DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE gps_weighted_location ADD source VARCHAR(30) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE gps_weighted_location weighted
            SET accuracy_m = cell.precision_m,
                source = 'legacy_address_cell'
            FROM address address
            JOIN geo_cell cell ON cell.id = address.geo_cell_id
            WHERE address.weighted_location_id = weighted.id
              AND weighted.accuracy_m IS NULL
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE gps_weighted_location_point (
                weighted_location_id BIGINT NOT NULL,
                gps_raw_point_id BIGINT NOT NULL,
                PRIMARY KEY (weighted_location_id, gps_raw_point_id),
                CONSTRAINT fk_weighted_location_point_weighted
                    FOREIGN KEY (weighted_location_id) REFERENCES gps_weighted_location (id) ON DELETE CASCADE,
                CONSTRAINT fk_weighted_location_point_raw
                    FOREIGN KEY (gps_raw_point_id) REFERENCES gps_raw_point (id) ON DELETE RESTRICT
            )
            SQL);

        $this->addSql('DROP INDEX idx_delivery_order_driver_status');
        $this->addSql('CREATE INDEX idx_delivery_order_driver_status_schedule ON delivery_order (assigned_driver_id, status, scheduled_at, id)');
        $this->addSql('CREATE INDEX idx_delivery_order_driver_completed ON delivery_order (assigned_driver_id, completed_at DESC, id DESC) WHERE status = \'DELIVERED\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_delivery_order_driver_completed');
        $this->addSql('DROP INDEX idx_delivery_order_driver_status_schedule');
        $this->addSql('CREATE INDEX idx_delivery_order_driver_status ON delivery_order (assigned_driver_id, status, id)');
        $this->addSql('DROP TABLE gps_weighted_location_point');
        $this->addSql('ALTER TABLE gps_weighted_location DROP source');
        $this->addSql('ALTER TABLE gps_weighted_location DROP accuracy_m');
        $this->addSql('DROP TABLE delivery_driver_earning');
    }
}
