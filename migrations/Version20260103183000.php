<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260103183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unique constraint on address phone_display + geo_cell_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE address ADD CONSTRAINT uniq_address_phone_cell UNIQUE (phone_display, geo_cell_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE address DROP CONSTRAINT uniq_address_phone_cell');
    }
}
