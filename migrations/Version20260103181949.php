<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260103181949 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initialisation base SaaS Adressage (PostgreSQL + PostGIS)';
    }

    public function up(Schema $schema): void
    {
        /* ==========================
         * EXTENSIONS POSTGIS
         * ========================== */
        $this->addSql('CREATE EXTENSION IF NOT EXISTS postgis');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS btree_gist');

        /* ==========================
         * GEO ADMIN AREA
         * ========================== */
        $this->addSql("
            CREATE TABLE geo_admin_area (
                id SERIAL PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                type VARCHAR(50) NOT NULL,
                parent_id INT REFERENCES geo_admin_area(id),
                boundary GEOGRAPHY(MULTIPOLYGON, 4326)
            )
        ");

        /* ==========================
         * GEO CELL
         * ========================== */
        $this->addSql("
            CREATE TABLE geo_cell (
                id BIGSERIAL PRIMARY KEY,
                cell_code VARCHAR(32) UNIQUE NOT NULL,
                precision_m INT NOT NULL,
                centroid GEOGRAPHY(POINT, 4326) NOT NULL,
                polygon GEOGRAPHY(POLYGON, 4326)
            )
        ");

        $this->addSql("
            CREATE INDEX idx_geo_cell_polygon
            ON geo_cell
            USING GIST (polygon)
        ");

        /* ==========================
         * GEO PLUS CODE
         * ========================== */
        $this->addSql("
            CREATE TABLE geo_plus_code (
                id BIGSERIAL PRIMARY KEY,
                plus_code VARCHAR(20) UNIQUE NOT NULL,
                precision_level INT,
                location GEOGRAPHY(POINT, 4326)
            )
        ");

        /* ==========================
         * GPS RAW POINT
         * ========================== */
        $this->addSql("
            CREATE TABLE gps_raw_point (
                id BIGSERIAL PRIMARY KEY,
                latitude DOUBLE PRECISION NOT NULL,
                longitude DOUBLE PRECISION NOT NULL,
                accuracy_m FLOAT,
                source VARCHAR(20),
                geom GEOGRAPHY(POINT, 4326),
                collected_at TIMESTAMP DEFAULT now()
            )
        ");

        $this->addSql("
            CREATE INDEX idx_gps_raw_geom
            ON gps_raw_point
            USING GIST (geom)
        ");

        /* ==========================
         * GPS WEIGHTED LOCATION
         * ========================== */
        $this->addSql("
            CREATE TABLE gps_weighted_location (
                id BIGSERIAL PRIMARY KEY,
                final_geom GEOGRAPHY(POINT, 4326) NOT NULL,
                confidence_score FLOAT NOT NULL,
                points_used INT NOT NULL,
                computed_at TIMESTAMP DEFAULT now()
            )
        ");

        /* ==========================
         * GPS OUTLIER
         * ========================== */
        $this->addSql("
            CREATE TABLE gps_outlier (
                id BIGSERIAL PRIMARY KEY,
                gps_point_id BIGINT REFERENCES gps_raw_point(id) ON DELETE CASCADE,
                reason TEXT,
                detected_at TIMESTAMP DEFAULT now()
            )
        ");

        /* ==========================
         * ADDRESS
         * ========================== */
        $this->addSql("
            CREATE TABLE address (
                id BIGSERIAL PRIMARY KEY,
                address_code VARCHAR(50) UNIQUE NOT NULL,
                phone_display VARCHAR(30),
                geo_cell_id BIGINT REFERENCES geo_cell(id),
                plus_code_id BIGINT REFERENCES geo_plus_code(id),
                weighted_location_id BIGINT REFERENCES gps_weighted_location(id),
                admin_area_id INT REFERENCES geo_admin_area(id),
                created_at TIMESTAMP DEFAULT now()
            )
        ");

        /* ==========================
         * ADDRESS VERSION
         * ========================== */
        $this->addSql("
            CREATE TABLE address_version (
                id BIGSERIAL PRIMARY KEY,
                address_id BIGINT REFERENCES address(id) ON DELETE CASCADE,
                location GEOGRAPHY(POINT, 4326),
                reason TEXT,
                versioned_at TIMESTAMP DEFAULT now()
            )
        ");

        /* ==========================
         * USER ACCOUNT
         * ========================== */
        $this->addSql("
            CREATE TABLE user_account (
                id BIGSERIAL PRIMARY KEY,
                phone VARCHAR(20) UNIQUE NOT NULL,
                name VARCHAR(100),
                verified BOOLEAN DEFAULT false,
                created_at TIMESTAMP DEFAULT now()
            )
        ");

        /* ==========================
         * USER ADDRESS
         * ========================== */
        $this->addSql("
            CREATE TABLE user_address (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT REFERENCES user_account(id) ON DELETE CASCADE,
                address_id BIGINT REFERENCES address(id) ON DELETE CASCADE,
                is_primary BOOLEAN DEFAULT false,
                UNIQUE (user_id, address_id)
            )
        ");

        /* ==========================
         * FRAUD EVENT
         * ========================== */
        $this->addSql("
            CREATE TABLE fraud_event (
                id BIGSERIAL PRIMARY KEY,
                entity_type VARCHAR(50),
                entity_id BIGINT,
                risk_level INT,
                description TEXT,
                detected_at TIMESTAMP DEFAULT now()
            )
        ");

        /* ==========================
         * AUDIT LOG
         * ========================== */
        $this->addSql("
            CREATE TABLE audit_log (
                id BIGSERIAL PRIMARY KEY,
                actor VARCHAR(50),
                action VARCHAR(100),
                target VARCHAR(100),
                ip_address VARCHAR(45),
                created_at TIMESTAMP DEFAULT now()
            )
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS audit_log');
        $this->addSql('DROP TABLE IF EXISTS fraud_event');
        $this->addSql('DROP TABLE IF EXISTS user_address');
        $this->addSql('DROP TABLE IF EXISTS user_account');
        $this->addSql('DROP TABLE IF EXISTS address_version');
        $this->addSql('DROP TABLE IF EXISTS address');
        $this->addSql('DROP TABLE IF EXISTS gps_outlier');
        $this->addSql('DROP TABLE IF EXISTS gps_weighted_location');
        $this->addSql('DROP TABLE IF EXISTS gps_raw_point');
        $this->addSql('DROP TABLE IF EXISTS geo_plus_code');
        $this->addSql('DROP TABLE IF EXISTS geo_cell');
        $this->addSql('DROP TABLE IF EXISTS geo_admin_area');
    }
}
