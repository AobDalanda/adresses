<?php

namespace App\Tests\Service;

use App\Service\OtpService;
use App\Service\WhatsAppOtpClient;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class OtpServiceTest extends TestCase
{
    public function testRequestOtpMarksDeliveryAsFailedAndRethrows(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchOne')->willReturn(false);
        $statements = [];
        $db->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $parameters) use (&$statements): int {
                $statements[] = [$sql, $parameters];

                return 1;
            });

        $whatsApp = $this->createMock(WhatsAppOtpClient::class);
        $whatsApp->method('sendOtp')->willThrowException(new \RuntimeException('Evolution unavailable'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $service = new OtpService($db, $whatsApp, $logger);

        try {
            $service->requestOtp('+224 620 00 00 00');
            self::fail('The delivery exception should be rethrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Evolution unavailable', $exception->getMessage());
        }

        self::assertStringContainsString('INSERT INTO otp_request', $statements[0][0]);
        self::assertSame('224620000000', $statements[0][1]['phone']);
        self::assertStringContainsString("SET status = 'FAILED'", $statements[1][0]);
        self::assertSame('224620000000', $statements[1][1]['phone']);
    }
}
