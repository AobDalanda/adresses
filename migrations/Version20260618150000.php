<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add configurable delivery pricing models, rules, zones, and surcharges';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'postgresql',
            'This migration can only be executed safely on PostgreSQL.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE pricing_models (
                id BIGSERIAL NOT NULL,
                name VARCHAR(120) NOT NULL,
                description TEXT DEFAULT NULL,
                valid_from TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                valid_to TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT now(),
                PRIMARY KEY (id),
                CONSTRAINT chk_pricing_model_validity CHECK (valid_to IS NULL OR valid_to > valid_from)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_pricing_models_active_validity ON pricing_models (is_active, valid_from, valid_to)');

        $this->addSql(<<<'SQL'
            CREATE TABLE service_types (
                id BIGSERIAL NOT NULL,
                code VARCHAR(40) NOT NULL,
                name VARCHAR(80) NOT NULL,
                description TEXT DEFAULT NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT now(),
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_service_types_code ON service_types (code)');

        $this->addSql(<<<'SQL'
            CREATE TABLE vehicle_types (
                id BIGSERIAL NOT NULL,
                code VARCHAR(40) NOT NULL,
                name VARCHAR(80) NOT NULL,
                description TEXT DEFAULT NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT now(),
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_vehicle_types_code ON vehicle_types (code)');

        $this->addSql(<<<'SQL'
            CREATE TABLE zones (
                id BIGSERIAL NOT NULL,
                name VARCHAR(120) NOT NULL,
                parent_zone_id BIGINT DEFAULT NULL,
                admin_area_id INT DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT now(),
                PRIMARY KEY (id),
                CONSTRAINT fk_zones_parent FOREIGN KEY (parent_zone_id) REFERENCES zones (id) ON DELETE SET NULL,
                CONSTRAINT fk_zones_admin_area FOREIGN KEY (admin_area_id) REFERENCES geo_admin_area (id) ON DELETE SET NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_zones_parent ON zones (parent_zone_id)');
        $this->addSql('CREATE INDEX idx_zones_admin_area ON zones (admin_area_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_zones_name_parent ON zones (name, COALESCE(parent_zone_id, 0))');

        $this->addSql(<<<'SQL'
            CREATE TABLE pricing_rules (
                id BIGSERIAL NOT NULL,
                pricing_model_id BIGINT NOT NULL,
                service_type_id BIGINT NOT NULL,
                vehicle_type_id BIGINT NOT NULL,
                zone_id BIGINT DEFAULT NULL,
                distance_min NUMERIC(10, 2) NOT NULL,
                distance_max NUMERIC(10, 2) DEFAULT NULL,
                base_price INT NOT NULL,
                price_per_km INT NOT NULL,
                priority INT NOT NULL DEFAULT 0,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT now(),
                PRIMARY KEY (id),
                CONSTRAINT fk_pricing_rules_model FOREIGN KEY (pricing_model_id) REFERENCES pricing_models (id) ON DELETE RESTRICT,
                CONSTRAINT fk_pricing_rules_service FOREIGN KEY (service_type_id) REFERENCES service_types (id) ON DELETE RESTRICT,
                CONSTRAINT fk_pricing_rules_vehicle FOREIGN KEY (vehicle_type_id) REFERENCES vehicle_types (id) ON DELETE RESTRICT,
                CONSTRAINT fk_pricing_rules_zone FOREIGN KEY (zone_id) REFERENCES zones (id) ON DELETE RESTRICT,
                CONSTRAINT chk_pricing_rules_distance_min CHECK (distance_min >= 0),
                CONSTRAINT chk_pricing_rules_distance_range CHECK (distance_max IS NULL OR distance_max > distance_min),
                CONSTRAINT chk_pricing_rules_prices CHECK (base_price >= 0 AND price_per_km >= 0)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_pricing_rules_lookup ON pricing_rules (pricing_model_id, service_type_id, vehicle_type_id, zone_id, is_active, priority)');

        $this->addSql(<<<'SQL'
            CREATE TABLE pricing_surcharges (
                id BIGSERIAL NOT NULL,
                pricing_model_id BIGINT NOT NULL,
                name VARCHAR(120) NOT NULL,
                type VARCHAR(20) NOT NULL,
                value NUMERIC(12, 2) NOT NULL,
                condition_json JSONB NOT NULL DEFAULT '{}'::jsonb,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT now(),
                PRIMARY KEY (id),
                CONSTRAINT fk_pricing_surcharges_model FOREIGN KEY (pricing_model_id) REFERENCES pricing_models (id) ON DELETE RESTRICT,
                CONSTRAINT chk_pricing_surcharges_type CHECK (type IN ('fixed', 'percentage')),
                CONSTRAINT chk_pricing_surcharges_value CHECK (value >= 0),
                CONSTRAINT chk_pricing_surcharges_condition CHECK (jsonb_typeof(condition_json) = 'object')
            )
            SQL);
        $this->addSql('CREATE INDEX idx_pricing_surcharges_model_active ON pricing_surcharges (pricing_model_id, is_active)');

        $this->addSql(<<<'SQL'
            INSERT INTO service_types (code, name, description)
            VALUES
                ('STANDARD', 'Standard', 'Livraison standard'),
                ('FRAGILE', 'Fragile', 'Colis fragile'),
                ('VOLUMINEUX', 'Volumineux', 'Colis volumineux'),
                ('EXPRESS', 'Express', 'Livraison express')
            ON CONFLICT (code) DO NOTHING
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO vehicle_types (code, name, description)
            VALUES
                ('MOTO', 'Moto', 'Livraison en moto'),
                ('VOITURE', 'Voiture', 'Livraison en voiture'),
                ('CAMIONNETTE', 'Camionnette', 'Livraison en camionnette'),
                ('CAMION', 'Camion', 'Livraison en camion')
            ON CONFLICT (code) DO NOTHING
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO zones (name)
            VALUES ('DEFAULT')
            ON CONFLICT DO NOTHING
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO pricing_models (name, description, valid_from, is_active)
            VALUES ('Default delivery pricing 2026', 'Initial configurable delivery pricing model.', now(), TRUE)
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO pricing_rules (
                pricing_model_id,
                service_type_id,
                vehicle_type_id,
                zone_id,
                distance_min,
                distance_max,
                base_price,
                price_per_km,
                priority,
                is_active
            )
            SELECT
                model.id,
                service.id,
                vehicle.id,
                zone.id,
                0,
                NULL,
                CASE service.code
                    WHEN 'EXPRESS' THEN 20000
                    ELSE 15000
                END,
                CASE service.code
                    WHEN 'FRAGILE' THEN 1800
                    WHEN 'VOLUMINEUX' THEN 2200
                    WHEN 'EXPRESS' THEN 2500
                    ELSE 1500
                END,
                0,
                TRUE
            FROM pricing_models model
            CROSS JOIN service_types service
            CROSS JOIN vehicle_types vehicle
            CROSS JOIN zones zone
            WHERE model.name = 'Default delivery pricing 2026'
              AND zone.name = 'DEFAULT'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pricing_surcharges');
        $this->addSql('DROP TABLE pricing_rules');
        $this->addSql('DROP TABLE zones');
        $this->addSql('DROP TABLE vehicle_types');
        $this->addSql('DROP TABLE service_types');
        $this->addSql('DROP TABLE pricing_models');
    }
}
