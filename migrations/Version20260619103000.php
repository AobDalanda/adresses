<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260619103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add global fallback delivery pricing rule support and seed the standard base rule';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'postgresql',
            'This migration can only be executed safely on PostgreSQL.',
        );

        $this->addSql('ALTER TABLE pricing_rules ADD code VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE pricing_rules ADD name VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE pricing_rules ADD currency VARCHAR(3) NOT NULL DEFAULT \'GNF\'');
        $this->addSql('ALTER TABLE pricing_rules ALTER service_type_id DROP NOT NULL');
        $this->addSql('ALTER TABLE pricing_rules ALTER vehicle_type_id DROP NOT NULL');

        $this->addSql(<<<'SQL'
            UPDATE pricing_rules
            SET
                code = CONCAT('RULE_', id),
                name = 'Règle tarifaire ' || id
            WHERE code IS NULL OR name IS NULL
            SQL);

        $this->addSql('ALTER TABLE pricing_rules ALTER code SET NOT NULL');
        $this->addSql('ALTER TABLE pricing_rules ALTER name SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_pricing_rules_code ON pricing_rules (code)');
        $this->addSql('DROP INDEX idx_pricing_rules_lookup');
        $this->addSql('CREATE INDEX idx_pricing_rules_lookup ON pricing_rules (pricing_model_id, service_type_id, vehicle_type_id, zone_id, is_active, priority)');

        $this->addSql(<<<'SQL'
            UPDATE pricing_rules
            SET
                code = CONCAT(
                    'RULE_',
                    COALESCE((SELECT code FROM service_types WHERE id = pricing_rules.service_type_id), 'ALL'),
                    '_',
                    COALESCE((SELECT code FROM vehicle_types WHERE id = pricing_rules.vehicle_type_id), 'ALL'),
                    '_',
                    pricing_rules.id
                ),
                name = CONCAT(
                    'Tarif ',
                    COALESCE((SELECT name FROM service_types WHERE id = pricing_rules.service_type_id), 'Tous services'),
                    ' / ',
                    COALESCE((SELECT name FROM vehicle_types WHERE id = pricing_rules.vehicle_type_id), 'Tous véhicules')
                )
            WHERE code LIKE 'RULE_%'
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO pricing_rules (
                pricing_model_id,
                service_type_id,
                vehicle_type_id,
                zone_id,
                code,
                name,
                distance_min,
                distance_max,
                base_price,
                price_per_km,
                currency,
                priority,
                is_active
            )
            SELECT
                model.id,
                NULL,
                NULL,
                NULL,
                'TARIF_STANDARD_BASE',
                'Tarif standard de base',
                0,
                NULL,
                5000,
                1000,
                'GNF',
                100,
                TRUE
            FROM pricing_models model
            WHERE model.name = 'Default delivery pricing 2026'
              AND model.is_active = TRUE
              AND NOT EXISTS (
                  SELECT 1
                  FROM pricing_rules rule
                  WHERE rule.pricing_model_id = model.id
                    AND rule.code = 'TARIF_STANDARD_BASE'
              )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM pricing_rules WHERE code = \'TARIF_STANDARD_BASE\'');
        $this->addSql('DROP INDEX uniq_pricing_rules_code');
        $this->addSql('ALTER TABLE pricing_rules DROP COLUMN currency');
        $this->addSql('ALTER TABLE pricing_rules DROP COLUMN name');
        $this->addSql('ALTER TABLE pricing_rules DROP COLUMN code');
        $this->addSql('ALTER TABLE pricing_rules ALTER service_type_id SET NOT NULL');
        $this->addSql('ALTER TABLE pricing_rules ALTER vehicle_type_id SET NOT NULL');
        $this->addSql('DROP INDEX idx_pricing_rules_lookup');
        $this->addSql('CREATE INDEX idx_pricing_rules_lookup ON pricing_rules (pricing_model_id, service_type_id, vehicle_type_id, zone_id, is_active, priority)');
    }
}
