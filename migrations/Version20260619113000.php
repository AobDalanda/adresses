<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260619113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add customer type support to pricing rules';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'postgresql',
            'This migration can only be executed safely on PostgreSQL.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE customer_types (
                id BIGSERIAL NOT NULL,
                code VARCHAR(40) NOT NULL,
                name VARCHAR(80) NOT NULL,
                description TEXT DEFAULT NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT now(),
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_customer_types_code ON customer_types (code)');

        $this->addSql('ALTER TABLE pricing_rules ADD customer_type_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE pricing_rules ADD CONSTRAINT fk_pricing_rules_customer FOREIGN KEY (customer_type_id) REFERENCES customer_types (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX idx_pricing_rules_customer ON pricing_rules (customer_type_id)');

        $this->addSql(<<<'SQL'
            INSERT INTO customer_types (code, name, description)
            VALUES
                ('CLIENT', 'Client standard', 'Client particulier ou standard'),
                ('BUSINESS', 'Client entreprise', 'Client professionnel ou entreprise'),
                ('PROVIDER', 'Prestataire', 'Compte prestataire utilisant le module de livraison')
            ON CONFLICT (code) DO NOTHING
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pricing_rules DROP CONSTRAINT fk_pricing_rules_customer');
        $this->addSql('DROP INDEX idx_pricing_rules_customer');
        $this->addSql('ALTER TABLE pricing_rules DROP COLUMN customer_type_id');
        $this->addSql('DROP TABLE customer_types');
    }
}
