<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260619120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Correct the standard base pricing rule to use a distance-based fallback tariff';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'postgresql',
            'This migration can only be executed safely on PostgreSQL.',
        );

        $this->addSql(<<<'SQL'
            UPDATE pricing_rules
            SET
                base_price = 5000,
                price_per_km = 1000,
                currency = 'GNF'
            WHERE code = 'TARIF_STANDARD_BASE'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE pricing_rules
            SET
                base_price = 1000,
                price_per_km = 0,
                currency = 'GNF'
            WHERE code = 'TARIF_STANDARD_BASE'
            SQL);
    }
}
