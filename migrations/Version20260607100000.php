<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260607100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Repair provider accounts whose account type was downgraded to client';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE user_account AS account
            SET account_type = 'provider'
            WHERE account.account_type = 'client'
              AND EXISTS (
                  SELECT 1
                  FROM provider_profile AS profile
                  WHERE profile.user_id = account.id
              )
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Data repair is intentionally irreversible.
    }
}
