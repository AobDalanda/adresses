<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260606110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les tables métier pour l’inscription livreur détaillée';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE driver_application (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT DEFAULT NULL REFERENCES user_account(id) ON DELETE SET NULL,
                phone VARCHAR(20) NOT NULL,
                signup_as VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
                submitted_at TIMESTAMP DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT now(),
                updated_at TIMESTAMP NOT NULL DEFAULT now()
            )
        ");
        $this->addSql('CREATE INDEX idx_driver_application_user ON driver_application (user_id)');
        $this->addSql('CREATE INDEX idx_driver_application_phone ON driver_application (phone)');

        $this->addSql("
            CREATE TABLE driver_vehicle (
                id BIGSERIAL PRIMARY KEY,
                application_id BIGINT NOT NULL REFERENCES driver_application(id) ON DELETE CASCADE,
                vehicle_type VARCHAR(20) NOT NULL,
                brand VARCHAR(100) DEFAULT NULL,
                model VARCHAR(100) DEFAULT NULL,
                license_plate VARCHAR(50) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT now(),
                updated_at TIMESTAMP NOT NULL DEFAULT now(),
                CONSTRAINT uniq_driver_vehicle_application UNIQUE (application_id)
            )
        ");

        $this->addSql("
            CREATE TABLE driver_license (
                id BIGSERIAL PRIMARY KEY,
                application_id BIGINT NOT NULL REFERENCES driver_application(id) ON DELETE CASCADE,
                license_number VARCHAR(100) DEFAULT NULL,
                category VARCHAR(20) DEFAULT NULL,
                expiry_date DATE DEFAULT NULL,
                license_photo_path VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT now(),
                updated_at TIMESTAMP NOT NULL DEFAULT now(),
                CONSTRAINT uniq_driver_license_application UNIQUE (application_id)
            )
        ");

        $this->addSql("
            CREATE TABLE driver_vehicle_document (
                id BIGSERIAL PRIMARY KEY,
                application_id BIGINT NOT NULL REFERENCES driver_application(id) ON DELETE CASCADE,
                document_type VARCHAR(30) NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT now()
            )
        ");
        $this->addSql('CREATE INDEX idx_driver_vehicle_document_application ON driver_vehicle_document (application_id)');

        $this->addSql("
            CREATE TABLE driver_vehicle_photo (
                id BIGSERIAL PRIMARY KEY,
                application_id BIGINT NOT NULL REFERENCES driver_application(id) ON DELETE CASCADE,
                file_path VARCHAR(255) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT now()
            )
        ");
        $this->addSql('CREATE INDEX idx_driver_vehicle_photo_application ON driver_vehicle_photo (application_id)');

        $this->addSql("
            CREATE TABLE driver_delivery_zone (
                id BIGSERIAL PRIMARY KEY,
                application_id BIGINT NOT NULL REFERENCES driver_application(id) ON DELETE CASCADE,
                zone_name VARCHAR(100) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT now()
            )
        ");
        $this->addSql('CREATE INDEX idx_driver_delivery_zone_application ON driver_delivery_zone (application_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS driver_delivery_zone');
        $this->addSql('DROP TABLE IF EXISTS driver_vehicle_photo');
        $this->addSql('DROP TABLE IF EXISTS driver_vehicle_document');
        $this->addSql('DROP TABLE IF EXISTS driver_license');
        $this->addSql('DROP TABLE IF EXISTS driver_vehicle');
        $this->addSql('DROP TABLE IF EXISTS driver_application');
    }
}
