<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260714190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enrich driver GPS tracking with recorded timestamp and quality flags';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE driver_location ADD recorded_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE driver_location ADD is_mocked BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE driver_location ADD is_suspect BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('UPDATE driver_location SET recorded_at = created_at WHERE recorded_at IS NULL');
        $this->addSql('ALTER TABLE driver_location ALTER recorded_at SET NOT NULL');
        $this->addSql('CREATE INDEX idx_driver_location_recorded_at ON driver_location (recorded_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_driver_location_recorded_at');
        $this->addSql('ALTER TABLE driver_location DROP recorded_at');
        $this->addSql('ALTER TABLE driver_location DROP is_mocked');
        $this->addSql('ALTER TABLE driver_location DROP is_suspect');
    }
}
