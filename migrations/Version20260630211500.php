<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260630211500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link deliveries to assigned drivers for private real-time tracking';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'postgresql',
            'This migration can only be executed safely on PostgreSQL.',
        );

        $this->addSql('ALTER TABLE delivery_order ADD assigned_driver_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_order ADD assigned_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE delivery_order
            ADD CONSTRAINT fk_delivery_order_assigned_driver
            FOREIGN KEY (assigned_driver_id) REFERENCES user_account (id) ON DELETE SET NULL
            SQL);
        $this->addSql('CREATE INDEX idx_delivery_order_driver_status ON delivery_order (assigned_driver_id, status, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_delivery_order_driver_status');
        $this->addSql('ALTER TABLE delivery_order DROP CONSTRAINT fk_delivery_order_assigned_driver');
        $this->addSql('ALTER TABLE delivery_order DROP assigned_at');
        $this->addSql('ALTER TABLE delivery_order DROP assigned_driver_id');
    }
}
