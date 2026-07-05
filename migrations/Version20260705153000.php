<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260705153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add delivery payment source of truth for customer fare payments';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'postgresql',
            'This migration can only be executed safely on PostgreSQL.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE delivery_payment (
                id BIGSERIAL NOT NULL,
                delivery_order_id BIGINT NOT NULL,
                amount NUMERIC(12, 2) NOT NULL,
                currency VARCHAR(3) NOT NULL,
                status VARCHAR(20) NOT NULL,
                payment_method VARCHAR(40) DEFAULT NULL,
                provider_reference VARCHAR(120) DEFAULT NULL,
                paid_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_delivery_payment_order
                    FOREIGN KEY (delivery_order_id) REFERENCES delivery_order (id) ON DELETE CASCADE,
                CONSTRAINT chk_delivery_payment_amount
                    CHECK (amount >= 0),
                CONSTRAINT chk_delivery_payment_status
                    CHECK (status IN ('PENDING', 'PAID', 'FAILED', 'CANCELLED'))
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_delivery_payment_order ON delivery_payment (delivery_order_id)');
        $this->addSql('CREATE INDEX idx_delivery_payment_status_paid_at ON delivery_payment (status, paid_at DESC, id DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE delivery_payment');
    }
}
