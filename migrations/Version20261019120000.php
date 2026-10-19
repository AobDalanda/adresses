<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add configurable GPS accuracy policy';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE gps_accuracy_policy (
                id SMALLINT NOT NULL,
                target_accuracy_meters INT NOT NULL,
                updated_by BIGINT DEFAULT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT chk_gps_accuracy_policy_singleton CHECK (id = 1),
                CONSTRAINT chk_gps_accuracy_policy_value CHECK (target_accuracy_meters BETWEEN 1 AND 500),
                CONSTRAINT fk_gps_accuracy_policy_user FOREIGN KEY (updated_by) REFERENCES user_account (id) ON DELETE SET NULL
            )
            SQL);
        $this->addSql("INSERT INTO gps_accuracy_policy (id, target_accuracy_meters, updated_at) VALUES (1, 5, now())");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE gps_accuracy_policy');
    }
}
