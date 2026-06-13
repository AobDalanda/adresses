<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260613160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add daily API version usage metrics for the provider v1 migration';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'postgresql',
            'This migration can only be executed safely on PostgreSQL.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE provider_api_usage_daily (
                usage_date DATE NOT NULL,
                api_version VARCHAR(8) NOT NULL,
                route_name VARCHAR(190) NOT NULL,
                client_version VARCHAR(80) NOT NULL,
                response_class SMALLINT NOT NULL,
                request_count BIGINT NOT NULL DEFAULT 0,
                PRIMARY KEY (usage_date, api_version, route_name, client_version, response_class),
                CONSTRAINT chk_provider_api_usage_version CHECK (api_version IN ('v1', 'v2')),
                CONSTRAINT chk_provider_api_usage_response_class
                    CHECK (response_class >= 1 AND response_class <= 5),
                CONSTRAINT chk_provider_api_usage_count CHECK (request_count >= 0)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_provider_api_usage_version_date ON provider_api_usage_daily (api_version, usage_date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE provider_api_usage_daily');
    }
}
