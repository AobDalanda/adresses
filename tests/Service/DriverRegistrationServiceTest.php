<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\DriverRegistrationInput;
use App\Service\DriverRegistrationService;
use App\Service\ProviderCanonicalRegistrationWriter;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class DriverRegistrationServiceTest extends TestCase
{
    public function testV1ResponseIsUnchangedAndCanonicalWriteCanBeDisabled(): void
    {
        $db = $this->legacyConnection();
        $writer = $this->createMock(ProviderCanonicalRegistrationWriter::class);
        $writer->expects(self::never())->method('write');

        $result = (new DriverRegistrationService($db, $writer, false))
            ->register(42, $this->input(), '127.0.0.1');

        self::assertSame(['applicationId' => 73, 'status' => 'PENDING'], $result);
    }

    public function testCanonicalWriteReceivesTheLegacyApplicationIdentifier(): void
    {
        $db = $this->legacyConnection();
        $input = $this->input();
        $writer = $this->createMock(ProviderCanonicalRegistrationWriter::class);
        $writer->expects(self::once())->method('write')->with(42, 73, $input);

        $result = (new DriverRegistrationService($db, $writer, true))
            ->register(42, $input, null);

        self::assertSame(['applicationId' => 73, 'status' => 'PENDING'], $result);
    }

    public function testCanonicalFailureAbortsTheV1RegistrationTransaction(): void
    {
        $db = $this->legacyConnection();
        $input = $this->input();
        $writer = $this->createMock(ProviderCanonicalRegistrationWriter::class);
        $writer->expects(self::once())
            ->method('write')
            ->with(42, 73, $input)
            ->willThrowException(new \RuntimeException('canonical write failed'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('canonical write failed');

        (new DriverRegistrationService($db, $writer, true))->register(42, $input);
    }

    private function legacyConnection(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('transactional')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $db->expects(self::once())->method('fetchOne')->willReturn('73');
        $db->method('executeStatement')->willReturn(1);

        return $db;
    }

    private function input(): DriverRegistrationInput
    {
        return new DriverRegistrationInput(
            '620000000',
            '123456',
            'LIVREUR',
            'Provider One',
            'provider@example.test',
            'ID-42',
            'identity/id-42.jpg',
            [
                'type' => 'A_PIED',
                'brand' => null,
                'model' => null,
                'licensePlate' => null,
                'deliveryZones' => ['Conakry'],
            ],
            [
                'number' => null,
                'category' => null,
                'expiryDate' => null,
                'photoPath' => null,
            ],
            [
                'insurancePath' => null,
                'registrationPath' => null,
                'registrationFrontPath' => null,
                'registrationBackPath' => null,
            ],
            [],
        );
    }
}
