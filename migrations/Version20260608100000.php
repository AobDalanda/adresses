<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260608100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Harden provider registration constraints and document uniqueness';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_user_account_identity_document_number
            ON user_account (lower(identity_document_number))
            WHERE identity_document_number IS NOT NULL AND btrim(identity_document_number) <> ''
            SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_driver_license_number
            ON driver_license (lower(license_number))
            WHERE license_number IS NOT NULL AND btrim(license_number) <> ''
            SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_driver_vehicle_license_plate
            ON driver_vehicle (upper(license_plate))
            WHERE license_plate IS NOT NULL AND btrim(license_plate) <> ''
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE driver_application
            ADD CONSTRAINT chk_driver_application_signup_as
            CHECK (signup_as IN ('LIVREUR', 'TRANSPORTEUR', 'BOTH'))
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE driver_application
            ADD CONSTRAINT chk_driver_application_status
            CHECK (status IN ('PENDING', 'APPROVED', 'REJECTED', 'SUSPENDED'))
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE driver_vehicle
            ADD CONSTRAINT chk_driver_vehicle_type
            CHECK (vehicle_type IN ('MOTO', 'VOITURE', 'VELO', 'A_PIED'))
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE driver_vehicle
            ADD CONSTRAINT chk_driver_vehicle_required_fields
            CHECK (
                vehicle_type = 'A_PIED'
                OR (
                    brand IS NOT NULL
                    AND model IS NOT NULL
                    AND license_plate IS NOT NULL
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE driver_license
            ADD CONSTRAINT chk_driver_license_complete
            CHECK (
                license_number IS NOT NULL
                AND category IS NOT NULL
                AND expiry_date IS NOT NULL
                AND license_photo_path IS NOT NULL
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE driver_license DROP CONSTRAINT chk_driver_license_complete');
        $this->addSql('ALTER TABLE driver_vehicle DROP CONSTRAINT chk_driver_vehicle_required_fields');
        $this->addSql('ALTER TABLE driver_vehicle DROP CONSTRAINT chk_driver_vehicle_type');
        $this->addSql('ALTER TABLE driver_application DROP CONSTRAINT chk_driver_application_status');
        $this->addSql('ALTER TABLE driver_application DROP CONSTRAINT chk_driver_application_signup_as');
        $this->addSql('DROP INDEX uniq_driver_vehicle_license_plate');
        $this->addSql('DROP INDEX uniq_driver_license_number');
        $this->addSql('DROP INDEX uniq_user_account_identity_document_number');
    }
}
