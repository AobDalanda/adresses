<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260619130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add delivery order, package, pricing snapshot, and status history tables';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'postgresql',
            'This migration can only be executed safely on PostgreSQL.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE delivery_order (
                id BIGSERIAL NOT NULL,
                public_id UUID NOT NULL,
                customer_id BIGINT NOT NULL,
                pickup_address_id BIGINT NOT NULL,
                dropoff_address_id BIGINT NOT NULL,
                service_type_code VARCHAR(40) NOT NULL,
                vehicle_type_code VARCHAR(40) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
                scheduled_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                recipient_name VARCHAR(120) DEFAULT NULL,
                recipient_phone VARCHAR(30) DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                confirmed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                cancelled_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                completed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_delivery_order_customer
                    FOREIGN KEY (customer_id) REFERENCES user_account (id) ON DELETE CASCADE,
                CONSTRAINT fk_delivery_order_pickup_address
                    FOREIGN KEY (pickup_address_id) REFERENCES address (id) ON DELETE RESTRICT,
                CONSTRAINT fk_delivery_order_dropoff_address
                    FOREIGN KEY (dropoff_address_id) REFERENCES address (id) ON DELETE RESTRICT,
                CONSTRAINT fk_delivery_order_service_type
                    FOREIGN KEY (service_type_code) REFERENCES service_types (code) ON DELETE RESTRICT,
                CONSTRAINT fk_delivery_order_vehicle_type
                    FOREIGN KEY (vehicle_type_code) REFERENCES vehicle_types (code) ON DELETE RESTRICT,
                CONSTRAINT chk_delivery_order_status
                    CHECK (status IN (
                        'DRAFT',
                        'QUOTED',
                        'CONFIRMED',
                        'ASSIGNED',
                        'PICKED_UP',
                        'IN_TRANSIT',
                        'DELIVERED',
                        'CANCELLED',
                        'FAILED'
                    ))
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_delivery_order_public_id ON delivery_order (public_id)');
        $this->addSql('CREATE INDEX idx_delivery_order_customer_created_at ON delivery_order (customer_id, created_at DESC, id DESC)');
        $this->addSql('CREATE INDEX idx_delivery_order_status_created_at ON delivery_order (status, created_at DESC, id DESC)');

        $this->addSql(<<<'SQL'
            CREATE TABLE delivery_package (
                id BIGSERIAL NOT NULL,
                delivery_order_id BIGINT NOT NULL,
                description TEXT DEFAULT NULL,
                declared_value_amount NUMERIC(12, 2) DEFAULT NULL,
                declared_value_currency VARCHAR(3) DEFAULT NULL,
                weight_kg NUMERIC(8, 2) DEFAULT NULL,
                length_cm NUMERIC(8, 2) DEFAULT NULL,
                width_cm NUMERIC(8, 2) DEFAULT NULL,
                height_cm NUMERIC(8, 2) DEFAULT NULL,
                fragile BOOLEAN NOT NULL DEFAULT FALSE,
                photo_asset_id BIGINT DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_delivery_package_order
                    FOREIGN KEY (delivery_order_id) REFERENCES delivery_order (id) ON DELETE CASCADE,
                CONSTRAINT fk_delivery_package_photo_asset
                    FOREIGN KEY (photo_asset_id) REFERENCES uploaded_asset (id) ON DELETE SET NULL,
                CONSTRAINT chk_delivery_package_declared_value
                    CHECK (declared_value_amount IS NULL OR declared_value_amount >= 0),
                CONSTRAINT chk_delivery_package_weight
                    CHECK (weight_kg IS NULL OR weight_kg > 0),
                CONSTRAINT chk_delivery_package_length
                    CHECK (length_cm IS NULL OR length_cm > 0),
                CONSTRAINT chk_delivery_package_width
                    CHECK (width_cm IS NULL OR width_cm > 0),
                CONSTRAINT chk_delivery_package_height
                    CHECK (height_cm IS NULL OR height_cm > 0)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_delivery_package_order ON delivery_package (delivery_order_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE delivery_pricing_snapshot (
                id BIGSERIAL NOT NULL,
                delivery_order_id BIGINT NOT NULL,
                distance_km NUMERIC(8, 2) NOT NULL,
                duration_minutes INT NOT NULL,
                zone_id BIGINT DEFAULT NULL,
                customer_type_code VARCHAR(40) DEFAULT NULL,
                base_amount NUMERIC(12, 2) NOT NULL,
                surcharge_amount NUMERIC(12, 2) NOT NULL DEFAULT 0,
                total_amount NUMERIC(12, 2) NOT NULL,
                currency VARCHAR(3) NOT NULL,
                pricing_payload JSONB NOT NULL,
                quoted_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_delivery_pricing_snapshot_order
                    FOREIGN KEY (delivery_order_id) REFERENCES delivery_order (id) ON DELETE CASCADE,
                CONSTRAINT fk_delivery_pricing_snapshot_zone
                    FOREIGN KEY (zone_id) REFERENCES zones (id) ON DELETE SET NULL,
                CONSTRAINT fk_delivery_pricing_snapshot_customer_type
                    FOREIGN KEY (customer_type_code) REFERENCES customer_types (code) ON DELETE SET NULL,
                CONSTRAINT chk_delivery_pricing_snapshot_distance
                    CHECK (distance_km >= 0),
                CONSTRAINT chk_delivery_pricing_snapshot_duration
                    CHECK (duration_minutes > 0),
                CONSTRAINT chk_delivery_pricing_snapshot_base
                    CHECK (base_amount >= 0),
                CONSTRAINT chk_delivery_pricing_snapshot_surcharge
                    CHECK (surcharge_amount >= 0),
                CONSTRAINT chk_delivery_pricing_snapshot_total
                    CHECK (total_amount >= 0),
                CONSTRAINT chk_delivery_pricing_snapshot_payload
                    CHECK (jsonb_typeof(pricing_payload) = 'object')
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_delivery_pricing_snapshot_order ON delivery_pricing_snapshot (delivery_order_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE delivery_status_history (
                id BIGSERIAL NOT NULL,
                delivery_order_id BIGINT NOT NULL,
                status VARCHAR(20) NOT NULL,
                comment TEXT DEFAULT NULL,
                changed_by_user_id BIGINT DEFAULT NULL,
                changed_by_role VARCHAR(30) DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_delivery_status_history_order
                    FOREIGN KEY (delivery_order_id) REFERENCES delivery_order (id) ON DELETE CASCADE,
                CONSTRAINT fk_delivery_status_history_changed_by
                    FOREIGN KEY (changed_by_user_id) REFERENCES user_account (id) ON DELETE SET NULL,
                CONSTRAINT chk_delivery_status_history_status
                    CHECK (status IN (
                        'DRAFT',
                        'QUOTED',
                        'CONFIRMED',
                        'ASSIGNED',
                        'PICKED_UP',
                        'IN_TRANSIT',
                        'DELIVERED',
                        'CANCELLED',
                        'FAILED'
                    ))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_delivery_status_history_order_created_at ON delivery_status_history (delivery_order_id, created_at DESC, id DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE delivery_status_history');
        $this->addSql('DROP TABLE delivery_pricing_snapshot');
        $this->addSql('DROP TABLE delivery_package');
        $this->addSql('DROP TABLE delivery_order');
    }
}
