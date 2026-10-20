<?php

namespace App\Tests\Service;

use App\Service\UserAddressService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class UserAddressServiceTest extends TestCase
{
    public function testItArchivesAnUnownedAddressUsedByADelivery(): void
    {
        $executedSql = [];
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('beginTransaction');
        $db->expects(self::once())->method('commit');
        $db->expects(self::never())->method('rollBack');
        $db->method('fetchAssociative')->willReturn(['id' => 5, 'is_primary' => false]);
        $db->method('fetchOne')->willReturnCallback(static function (string $sql): int|bool {
            if (str_contains($sql, 'FOR UPDATE')) {
                return 42;
            }

            if (str_contains($sql, 'COUNT(*)')) {
                return 0;
            }

            if (str_contains($sql, 'FROM delivery_order')) {
                return true;
            }

            self::fail('Requête fetchOne inattendue : '.$sql);
        });
        $db->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$executedSql): int {
                $executedSql[] = $sql;

                return 1;
            }
        );

        self::assertTrue((new UserAddressService($db))->deleteUserAddress(7, 42));
        self::assertTrue($this->containsSql($executedSql, 'DELETE FROM user_address'));
        self::assertTrue($this->containsSql($executedSql, 'UPDATE address SET deleted_at'));
        self::assertFalse($this->containsSql($executedSql, 'DELETE FROM address'));
    }

    public function testItPhysicallyDeletesAnUnownedAddressWithoutDelivery(): void
    {
        $executedSql = [];
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn(['id' => 5, 'is_primary' => false]);
        $db->method('fetchOne')->willReturnCallback(static function (string $sql): int|bool {
            if (str_contains($sql, 'FOR UPDATE')) {
                return 42;
            }

            if (str_contains($sql, 'COUNT(*)')) {
                return 0;
            }

            if (str_contains($sql, 'FROM delivery_order')) {
                return false;
            }

            self::fail('Requête fetchOne inattendue : '.$sql);
        });
        $db->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$executedSql): int {
                $executedSql[] = $sql;

                return 1;
            }
        );

        self::assertTrue((new UserAddressService($db))->deleteUserAddress(7, 42));
        self::assertTrue($this->containsSql($executedSql, 'DELETE FROM address'));
        self::assertFalse($this->containsSql($executedSql, 'UPDATE address SET deleted_at'));
    }

    /** @param list<string> $queries */
    private function containsSql(array $queries, string $fragment): bool
    {
        foreach ($queries as $query) {
            if (str_contains($query, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
