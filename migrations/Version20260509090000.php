<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260509090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add contact_phone to address';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE address ADD contact_phone VARCHAR(20) DEFAULT NULL');
        $this->addSql('UPDATE address SET contact_phone = phone_display WHERE contact_phone IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE address DROP contact_phone');
    }
}
