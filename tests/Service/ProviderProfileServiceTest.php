<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ProviderProfileService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\TestCase;

final class ProviderProfileServiceTest extends TestCase
{
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
