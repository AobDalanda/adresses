<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\DeliveryGeographyException;
use App\Service\DeliveryGeographyPolicyService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class DeliveryGeographyPolicyServiceTest extends TestCase
{
    public function testDomesticModeAllowsConfiguredCountry(): void
    {
        $service = $this->service('DOMESTIC_ONLY', ['FR', 'GN'], true);

        $service->assertDeliveryAllowed('gn', 'GN');
        $service->assertDeliveryAllowed('FR', 'fr');

        self::addToAssertionCount(1);
    }

    public function testDomesticModeRejectsCountryOutsideAllowList(): void
    {
        $service = $this->service('DOMESTIC_ONLY', ['FR', 'GN'], true);

        try {
            $service->assertDeliveryAllowed('GN', 'CI');
            self::fail('Une livraison vers un pays non autorisé aurait dû être refusée.');
        } catch (DeliveryGeographyException $exception) {
            self::assertSame('DELIVERY_COUNTRY_NOT_ALLOWED', $exception->getErrorCode());
            self::assertSame(['FR', 'GN'], $exception->getAllowedCountryCodes());
        }
    }

    public function testSameCountryModeAcceptsAnySingleCountryAndRejectsCrossBorder(): void
    {
        $service = $this->service('SAME_COUNTRY', [], true);
        $service->assertDeliveryAllowed('SN', 'SN');

        $this->expectException(DeliveryGeographyException::class);
        $this->expectExceptionMessage('doivent être dans le même pays');
        $service->assertDeliveryAllowed('SN', 'GN');
    }

    public function testInternationalModeAllowsDifferentCountries(): void
    {
        $service = $this->service('INTERNATIONAL', [], false);

        $service->assertDeliveryAllowed('GN', 'SN');

        self::addToAssertionCount(1);
    }

    private function service(string $mode, array $countries, bool $requireSameCountry): DeliveryGeographyPolicyService
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn([
            'mode' => $mode,
            'allowed_country_codes' => json_encode($countries, JSON_THROW_ON_ERROR),
            'require_same_country' => $requireSameCountry,
            'enabled' => true,
            'updated_by' => null,
            'updated_at' => '2026-09-26T12:00:00+00:00',
        ]);

        return new DeliveryGeographyPolicyService($db);
    }
}
