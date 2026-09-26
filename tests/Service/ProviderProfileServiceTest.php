<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ProviderProfileService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\TestCase;

final class ProviderProfileServiceTest extends TestCase
{
    public function testApprovingProfileCreatesMatchingActiveAuthorizationAtomically(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback($connection));
        $connection->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            ['can_deliver' => true, 'can_transport_people' => false],
            [
                'id' => 2,
                'user_id' => 43,
                'can_deliver' => true,
                'can_transport_people' => false,
                'validation_status' => 'approved',
                'created_at' => '2026-09-26 12:00:00',
                'updated_at' => '2026-09-26 17:00:00',
                'phone' => '33781191499',
                'name' => 'Provider 43',
                'email' => null,
                'verified' => true,
                'account_type' => 'client',
            ],
        );
        $connection->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $parameters = []): int {
                if (str_contains($sql, 'INSERT INTO provider_authorization')) {
                    self::assertSame('ACTIVE', $parameters['authorizationStatus']);
                    self::assertTrue($parameters['canDeliver']);
                    self::assertFalse($parameters['canTransportPeople']);
                }

                return 1;
            });

        $profile = (new ProviderProfileService($connection))->updateStatus(2, 'approved');

        self::assertNotNull($profile);
        self::assertSame('approved', $profile['validationStatus']);
        self::assertTrue($profile['canDeliver']);
    }

    public function testTransportOnlyActivitiesUseBooleanDbalTypes(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback($connection));
        $connection->expects(self::once())
            ->method('fetchOne')
            ->willReturn('client');
        $connection->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(static function (
                string $sql,
                array $parameters = [],
                array $types = []
            ): int {
                if (str_contains($sql, 'INSERT INTO provider_profile')) {
                    self::assertFalse($parameters['canDeliver']);
                    self::assertTrue($parameters['canTransportPeople']);
                    self::assertSame(ParameterType::BOOLEAN, $types['canDeliver']);
                    self::assertSame(ParameterType::BOOLEAN, $types['canTransportPeople']);
                }

                return 1;
            });
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 8,
                'user_id' => 42,
                'can_deliver' => false,
                'can_transport_people' => true,
                'validation_status' => 'pending',
                'created_at' => '2026-06-08 18:00:00',
                'updated_at' => '2026-06-08 18:00:00',
                'phone' => '33652614186',
                'name' => 'Balde Aissatou',
                'email' => 'balde@example.com',
                'verified' => true,
                'account_type' => 'provider',
            ]);

        $profile = (new ProviderProfileService($connection))->submitActivities(42, false, true);

        self::assertFalse($profile['canDeliver']);
        self::assertTrue($profile['canTransportPeople']);
    }
}
