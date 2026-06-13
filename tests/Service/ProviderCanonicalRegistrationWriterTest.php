<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\DriverRegistrationInput;
use App\Service\ProviderCanonicalRegistrationWriter;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class ProviderCanonicalRegistrationWriterTest extends TestCase
{
    public function testWriteCreatesSubmittedCanonicalProjectionAndOutboxEvent(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(4))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls('9', '100', '1', '200');
        $db->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(false);

        $statements = [];
        $db->expects(self::exactly(4))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $parameters = []) use (&$statements): int {
                $statements[] = [$sql, $parameters];

                return 1;
            });

        (new ProviderCanonicalRegistrationWriter($db))->write(42, 73, $this->input('BOTH'));

        self::assertStringContainsString('UPDATE provider_application', $statements[0][0]);
        self::assertSame(73, $statements[0][1]['legacyApplicationId']);
        self::assertStringContainsString('INSERT INTO provider_authorization', $statements[1][0]);
        self::assertStringContainsString('INSERT INTO provider_decision_history', $statements[2][0]);
        self::assertStringContainsString('INSERT INTO outbox_event', $statements[3][0]);

        $payload = json_decode($statements[3][1]['payload'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(73, $payload['legacyDriverApplicationId']);
        self::assertSame(9, $payload['providerProfileId']);
        self::assertSame(1, $payload['revision']);
    }

    public function testWriteCompletesDraftCreatedByBackfillWithNextRevision(): void
    {
        $db = $this->createMock(Connection::class);
        $revisionParameters = null;
        $db->expects(self::exactly(3))
            ->method('fetchOne')
            ->willReturnCallback(static function (string $sql, array $parameters = []) use (&$revisionParameters): string {
                if (str_contains($sql, 'INSERT INTO provider_application_revision')) {
                    $revisionParameters = $parameters;

                    return '201';
                }

                return match (true) {
                    str_contains($sql, 'SELECT id FROM provider_profile') => '9',
                    str_contains($sql, 'COALESCE(MAX(version)') => '2',
                    default => throw new \LogicException('Unexpected query'),
                };
            });
        $db->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => '100',
                'public_id' => '01975aa9-df9c-7b25-b797-6b1ca912e68e',
                'status' => 'DRAFT',
                'current_revision_id' => '150',
                'legacy_driver_application_id' => null,
            ]);

        $db->expects(self::exactly(4))
            ->method('executeStatement')
            ->willReturn(1);

        (new ProviderCanonicalRegistrationWriter($db))->write(42, 73, $this->input('LIVREUR'));

        self::assertIsArray($revisionParameters);
        self::assertSame(2, $revisionParameters['version']);
        self::assertSame(150, $revisionParameters['supersedesRevisionId']);
        self::assertSame(['DELIVERY'], json_decode($revisionParameters['activities'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testWriteRejectsAnAlreadySubmittedApplicationBeforeMutation(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchOne')->willReturn('9');
        $db->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => '100',
                'public_id' => '01975aa9-df9c-7b25-b797-6b1ca912e68e',
                'status' => 'SUBMITTED',
                'current_revision_id' => '150',
                'legacy_driver_application_id' => '72',
            ]);
        $db->expects(self::never())->method('executeStatement');

        $this->expectException(\DomainException::class);
        (new ProviderCanonicalRegistrationWriter($db))->write(42, 73, $this->input('LIVREUR'));
    }

    private function input(string $signupAs): DriverRegistrationInput
    {
        return new DriverRegistrationInput(
            '620000000',
            '123456',
            $signupAs,
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
