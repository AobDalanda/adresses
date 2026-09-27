<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\DeliveryNotAvailableException;
use App\Exception\DeliveryOfferForbiddenException;

final readonly class DeliveryOfferAccessChecker
{
    public function __construct(
        private DriverAccessChecker $drivers,
        private NearbyDriverMatcher $matcher,
        private int $maxDistanceMeters = 1000,
    ) {
    }

    /** @param array<string, mixed> $delivery */
    public function assertEligible(int $driverId, array $delivery): void
    {
        $this->drivers->assertCanDeliver($driverId);

        if ((string) ($delivery['status'] ?? '') !== 'QUOTED' || ($delivery['assigned_driver_id'] ?? null) !== null) {
            throw new DeliveryNotAvailableException('DELIVERY_ALREADY_ACCEPTED');
        }

        $latitude = $delivery['pickup_latitude'] ?? null;
        $longitude = $delivery['pickup_longitude'] ?? null;
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            throw new DeliveryOfferForbiddenException('PICKUP_LOCATION_UNAVAILABLE');
        }

        $diagnostics = $this->matcher->evaluateDrivers(
            (float) $latitude,
            (float) $longitude,
            (string) ($delivery['service_type_code'] ?? ''),
            (string) ($delivery['vehicle_type_code'] ?? ''),
            $this->maxDistanceMeters,
            is_string($delivery['pickup_country_code'] ?? null) ? $delivery['pickup_country_code'] : null,
        );
        $diagnostic = current(array_filter(
            $diagnostics,
            static fn (array $candidate): bool => $candidate['driverId'] === $driverId,
        ));
        if ($diagnostic === false) {
            throw new DeliveryOfferForbiddenException('DRIVER_NOT_AUTHORIZED');
        }
        if (!$diagnostic['eligible']) {
            throw new DeliveryOfferForbiddenException($this->publicReason($diagnostic['rejectionReason']));
        }
    }

    private function publicReason(?string $reason): string
    {
        return match ($reason) {
            'OFFLINE' => 'DRIVER_UNAVAILABLE',
            'LOCATION_MISSING', 'LOCATION_STALE', 'ACCURACY_TOO_LOW', 'LOCATION_NOT_TRUSTED' => 'DRIVER_LOCATION_STALE',
            'OUTSIDE_RADIUS', 'COUNTRY_NOT_COMPATIBLE' => 'DRIVER_OUTSIDE_PICKUP_ZONE',
            'VEHICLE_NOT_COMPATIBLE', 'SERVICE_NOT_COMPATIBLE' => 'DRIVER_NOT_AUTHORIZED',
            default => 'DRIVER_NOT_AUTHORIZED',
        };
    }
}
