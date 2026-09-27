<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DeliveryOfferService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeliveryOfferService::class)]
final class DeliveryOfferServiceTest extends TestCase
{
    public function testMapsOfferToMobileContract(): void
    {
        $service = (new \ReflectionClass(DeliveryOfferService::class))->newInstanceWithoutConstructor();
        $map = new \ReflectionMethod(DeliveryOfferService::class, 'map');

        $payload = $map->invoke($service, [
            'id' => 123,
            'public_id' => '01975aa9-df9c-7b25-b797-6b1ca912e68f',
            'package_description' => 'Petit colis',
            'pickup_contact_phone' => '00224123456789',
            'pickup_address_id' => 10,
            'pickup_name' => 'Adresse de départ',
            'pickup_address' => 'ADDR-10',
            'pickup_latitude' => '9.6412',
            'pickup_longitude' => '-13.5784',
            'pickup_zone' => 'Conakry',
            'dropoff_address_id' => 11,
            'dropoff_name' => 'Adresse de destination',
            'dropoff_address' => 'ADDR-11',
            'dropoff_latitude' => '9.61',
            'dropoff_longitude' => '-13.60',
            'recipient_name' => 'Nom du client',
            'recipient_phone' => '003651896602',
            'total_amount' => '85000.00',
            'driver_earning' => '70000.00',
            'currency' => 'GNF',
            'distance_km' => '4.2',
            'duration_minutes' => 18,
        ]);

        $jsonPayload = json_decode((string) json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([
            'data' => [
                'delivery' => [
                    'id' => 123,
                    'deliveryId' => '01975aa9-df9c-7b25-b797-6b1ca912e68f',
                    'available' => true,
                    'status' => 'pending',
                    'distanceKm' => 4.2,
                    'durationMinutes' => 18,
                    'pricing' => [
                        'totalAmount' => 85000,
                        'driverEarning' => 70000,
                        'serviceFee' => 15000,
                        'currency' => 'GNF',
                    ],
                    'pickupAddress' => [
                        'name' => 'Point de départ',
                        'displayLabel' => 'Adresse de départ',
                    ],
                    'dropoffAddress' => [
                        'displayLabel' => 'Adresse de destination',
                    ],
                    'recipient' => [
                        'name' => 'Nom du client',
                        'phone' => '003651896602',
                    ],
                ],
            ],
        ], $jsonPayload);
    }
}
