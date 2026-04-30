<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260103184500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add display label to address';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE address ADD COLUMN display_label VARCHAR(120)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE address DROP COLUMN display_label');
    }
}
