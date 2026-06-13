<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\FcmPushClient;
use App\Service\ProviderNotificationService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ProviderNotificationServiceTest extends TestCase
{
    public function testKnownEventCreatesInAppNotificationAndSkipsPushWithoutDevice(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('fetchOne')
            ->willReturn('42');
        $statements = [];
        $db->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $parameters = []) use (&$statements): int {
                $statements[] = [$sql, $parameters];

                return 1;
            });
        $db->expects(self::once())
            ->method('fetchFirstColumn')
            ->willReturn([]);

        $service = new ProviderNotificationService(
            $db,
            new FcmPushClient(''),
            new NullLogger(),
        );
        $service->handle(
            '01975aa9-df9c-7b25-b797-6b1ca912e68e',
            'provider.application.submitted',
            '01975aa9-df9c-7b25-b797-6b1ca912e68f',
            ['applicationId' => '01975aa9-df9c-7b25-b797-6b1ca912e68f'],
        );

        self::assertStringContainsString('ON CONFLICT (source_event_id, user_id) DO NOTHING', $statements[0][0]);
        self::assertSame('provider.application.submitted', $statements[0][1]['type']);
        self::assertSame('SKIPPED', $statements[1][1]['status']);
    }

    public function testDuplicateNotificationDoesNotSendPushAgain(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchOne')->willReturn('42');
        $db->expects(self::once())->method('executeStatement')->willReturn(0);
        $db->expects(self::never())->method('fetchFirstColumn');

        $service = new ProviderNotificationService(
            $db,
            new FcmPushClient(''),
            new NullLogger(),
        );
        $service->handle(
            '01975aa9-df9c-7b25-b797-6b1ca912e68e',
            'provider.application.submitted',
            '01975aa9-df9c-7b25-b797-6b1ca912e68f',
            [],
        );
    }

    public function testUnknownEventIsIgnored(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('fetchOne');
        $db->expects(self::never())->method('executeStatement');

        $service = new ProviderNotificationService(
            $db,
            new FcmPushClient(''),
            new NullLogger(),
        );
        $service->handle('event-id', 'unknown.event', 'aggregate-id', []);
    }
}
