<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restore the canonical digits-only phone format for the affected provider account';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE user_account
            SET phone = '33781191499'
            WHERE phone = '+33781191499'
              AND NOT EXISTS (
                  SELECT 1
                  FROM user_account existing
                  WHERE existing.phone = '33781191499'
              )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'The non-canonical phone format must not be restored because authentication only uses digits.'
        );
    }
}
