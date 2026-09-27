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
        $totalAmount = $this->float($row['total_amount']);
        $driverEarning = $this->float($row['driver_earning']);

        return ['data' => ['delivery' => [
            'id' => (int) $row['id'],
            'deliveryId' => (string) $row['public_id'],
            // QUOTED is the persisted pre-assignment state; PENDING is the mobile API contract.
            'available' => true,
            'status' => 'pending',
            'distanceKm' => $this->float($row['distance_km']),
            'durationMinutes' => $row['duration_minutes'] !== null ? (int) $row['duration_minutes'] : null,
            'pricing' => [
                'totalAmount' => $totalAmount,
                'driverEarning' => $driverEarning,
                'serviceFee' => $totalAmount !== null && $driverEarning !== null
                    ? max(0.0, $totalAmount - $driverEarning)
                    : null,
                'currency' => $row['currency'] !== null ? (string) $row['currency'] : null,
            ],
            'pickupAddress' => [
                'name' => 'Point de départ',
                'displayLabel' => $row['pickup_name'] !== null ? (string) $row['pickup_name'] : null,
            ],
            'dropoffAddress' => [
                'displayLabel' => $row['dropoff_name'] !== null ? (string) $row['dropoff_name'] : null,
            ],
            'recipient' => [
                'name' => $row['recipient_name'] !== null ? (string) $row['recipient_name'] : null,
                'phone' => $row['recipient_phone'] !== null ? (string) $row['recipient_phone'] : null,
            ],
        ]]];
    }

    private function float(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
