<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\DeliveryNotFoundException;
use App\Repository\DeliveryOrderRepository;

final readonly class DeliveryOfferService
{
    public function __construct(
        private DeliveryOrderRepository $deliveries,
        private DeliveryOfferAccessChecker $access,
    ) {
    }

    /** @return array<string, mixed> */
    public function get(string $publicId, int $driverId): array
    {
        $delivery = $this->deliveries->findOffer($publicId);
        if ($delivery === null) {
            throw new DeliveryNotFoundException('DELIVERY_NOT_FOUND');
        }
        $this->access->assertEligible($driverId, $delivery);

        return $this->map($delivery);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function map(array $row): array
    {
        return [
            'id' => (string) $row['public_id'],
            'reference' => null,
            // QUOTED is the persisted pre-assignment state; PENDING is the mobile API contract.
            'status' => 'PENDING',
            'available' => true,
            'packageType' => null,
            'description' => $row['package_description'] !== null ? (string) $row['package_description'] : null,
            'contactPhone' => $row['pickup_contact_phone'] !== null ? (string) $row['pickup_contact_phone'] : null,
            'pickupAddress' => $this->address($row, 'pickup', true),
            'dropoffAddress' => $this->address($row, 'dropoff', false),
            'recipient' => [
                'name' => $row['recipient_name'] !== null ? (string) $row['recipient_name'] : null,
                'phone' => $row['recipient_phone'] !== null ? (string) $row['recipient_phone'] : null,
            ],
            'pricing' => [
                'totalAmount' => $this->float($row['total_amount']),
                'serviceFee' => null,
                'driverEarning' => $this->float($row['driver_earning']),
                'currency' => $row['currency'] !== null ? (string) $row['currency'] : null,
            ],
            'distanceKm' => $this->float($row['distance_km']),
            'durationMinutes' => $row['duration_minutes'] !== null ? (int) $row['duration_minutes'] : null,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function address(array $row, string $prefix, bool $includeZone): array
    {
        $result = [
            'id' => (int) $row[$prefix.'_address_id'],
            'name' => $row[$prefix.'_name'] !== null ? (string) $row[$prefix.'_name'] : null,
            'address' => (string) $row[$prefix.'_address'],
            'latitude' => $this->float($row[$prefix.'_latitude']),
            'longitude' => $this->float($row[$prefix.'_longitude']),
        ];
        if ($includeZone) {
            $result['zone'] = $row['pickup_zone'] !== null ? (string) $row['pickup_zone'] : null;
        }
        return $result;
    }

    private function float(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
