<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MissionOverviewService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class MissionOverviewServiceTest extends TestCase
{
    public function testListReturnsOnlyTheAuthenticatedDriversDeliveryMissions(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(self::callback(static fn (string $sql): bool =>
                str_contains($sql, 'delivery.assigned_driver_id = :driverId')
                && str_contains($sql, "delivery.status IN ('ASSIGNED', 'PICKED_UP', 'IN_TRANSIT', 'DELIVERED')")
                && str_contains($sql, 'LEFT JOIN delivery_proof proof ON proof.delivery_order_id = delivery.id')
                && !str_contains($sql, 'geo_plus_code')
            ))
            ->willReturn([$this->missionRow()]);
        $db->expects(self::once())->method('fetchOne')->willReturn(1);
        $db->expects(self::once())->method('fetchAssociative')->willReturn([
            'en_cours' => 1,
            'a_venir' => 2,
            'terminee' => 3,
        ]);

        $result = (new MissionOverviewService($db))->listForDriver(42, $this->filters());

        self::assertSame('livraison', $result['data'][0]['missionType']);
        self::assertSame('en_cours', $result['data'][0]['status']);
        self::assertSame('6500.00', $result['data'][0]['earning']['amount']);
        self::assertSame('GNF', $result['data'][0]['earning']['currency']);
        self::assertSame(12.0, $result['data'][0]['pickup']['gpsPrecisionM']);
        self::assertSame('driver_app', $result['data'][0]['pickup']['gpsSource']);
        self::assertSame(['en_cours' => 1, 'a_venir' => 2, 'terminee' => 3], $result['meta']['countsByStatus']);
        self::assertStringNotContainsString('plusCode', (string) json_encode($result));
    }

    public function testCompletedMissionUsesFinalEarningAndLoadsChronologicalHistory(): void
    {
        $row = $this->missionRow();
        $row['mission_status'] = 'terminee';
        $row['delivery_status'] = 'DELIVERED';
        $row['estimated_amount'] = '6500.00';
        $row['final_amount'] = '7000.00';
        $row['earning_settlement_status'] = 'PAID';
        $row['earning_settled_at'] = '2026-07-05T11:05:00+00:00';
        $row['proof_reception_code'] = '456789';
        $row['proof_recipient_name'] = 'M. Camara';
        $row['proof_signature_asset_id'] = 501;
        $row['proof_delivery_photo_asset_id'] = 502;
        $row['proof_signed_at'] = '2026-07-05T11:00:00+00:00';
        $row['proof_photo_captured_at'] = '2026-07-05T11:00:00+00:00';
        $row['proof_signature_bucket'] = 'private';
        $row['proof_signature_object_key'] = 'delivery-proofs/signature.png';
        $row['proof_photo_bucket'] = 'private';
        $row['proof_photo_object_key'] = 'delivery-proofs/photo.png';

        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('fetchAssociative')
            ->with(
                self::callback(static fn (string $sql): bool =>
                    str_contains($sql, 'delivery.assigned_driver_id = :driverId')
                    && str_contains($sql, 'delivery.public_id = :publicId')
                    && str_contains($sql, 'LEFT JOIN uploaded_asset photo_asset ON photo_asset.id = proof.delivery_photo_asset_id')
                ),
                self::callback(static fn (array $params): bool => $params['driverId'] === 42)
            )
            ->willReturn($row);
        $db->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(self::stringContains('ORDER BY created_at ASC'))
            ->willReturn([[
                'status' => 'DELIVERED',
                'comment' => null,
                'created_at' => '2026-07-05T11:00:00+00:00',
            ]]);
        $storage = $this->createMock(\App\Service\SupabaseStorageClient::class);
        $storage->expects(self::exactly(2))
            ->method('createSignedUrl')
            ->willReturnOnConsecutiveCalls(
                'https://cdn.test/signature',
                'https://cdn.test/photo',
            );

        $mission = (new MissionOverviewService($db, $storage))->detailForDriver(42, (string) $row['public_id']);

        self::assertSame('7000.00', $mission['earning']['amount']);
        self::assertFalse($mission['earning']['isEstimated']);
        self::assertSame('DELIVERED', $mission['history'][0]['status']);
        self::assertSame(8.4, $mission['summary']['distanceKm']);
        self::assertSame('7000.00', $mission['payment']['driverEarning']['amount']);
        self::assertSame('12500.00', $mission['payment']['amount']);
        self::assertSame('paid', $mission['payment']['status']);
        self::assertSame('2026-07-05T11:05:00+00:00', $mission['payment']['paidAt']);
        self::assertSame('available', $mission['deliveryProof']['status']);
        self::assertTrue($mission['deliveryProof']['recipientSignature']['required']);
        self::assertSame('456789', $mission['deliveryProof']['receptionCode']);
        self::assertSame('M. Camara', $mission['deliveryProof']['recipientName']);
        self::assertSame('https://cdn.test/signature', $mission['deliveryProof']['recipientSignature']['url']);
        self::assertSame('https://cdn.test/photo', $mission['deliveryProof']['deliveryPhoto']['url']);
    }

    /** @return array{status: string, page: int, perPage: int, sort: string, dateFrom: ?string, dateTo: ?string} */
    private function filters(): array
    {
        return [
            'status' => 'all',
            'page' => 1,
            'perPage' => 20,
            'sort' => 'default',
            'dateFrom' => null,
            'dateTo' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function missionRow(): array
    {
        return [
            'internal_id' => 12,
            'public_id' => '01975aa9-df9c-7b25-b797-6b1ca912e68f',
            'delivery_status' => 'IN_TRANSIT',
            'mission_status' => 'en_cours',
            'scheduled_at' => '2026-07-05T10:00:00+00:00',
            'assigned_at' => '2026-07-05T09:30:00+00:00',
            'started_at' => '2026-07-05T09:45:00+00:00',
            'completed_at' => null,
            'created_at' => '2026-07-05T09:00:00+00:00',
            'notes' => 'Appeler avant arrivée',
            'recipient_name' => 'Mamadou',
            'recipient_phone' => '+224620000000',
            'service_type_code' => 'STANDARD',
            'service_type_name' => 'Standard',
            'pickup_address_code' => 'ADR-PICKUP',
            'pickup_label' => 'Dixinn',
            'pickup_latitude' => 9.55,
            'pickup_longitude' => -13.68,
            'pickup_accuracy_m' => 12,
            'pickup_gps_source' => 'driver_app',
            'dropoff_address_code' => 'ADR-DROPOFF',
            'dropoff_label' => 'Matoto',
            'dropoff_latitude' => 9.57,
            'dropoff_longitude' => -13.61,
            'dropoff_accuracy_m' => 10,
            'dropoff_gps_source' => 'geocoder',
            'distance_km' => '8.40',
            'duration_minutes' => 22,
            'pricing_base_amount' => '11000.00',
            'pricing_surcharge_amount' => '1500.00',
            'pricing_total_amount' => '12500.00',
            'pricing_currency' => 'GNF',
            'estimated_amount' => '6500.00',
            'final_amount' => null,
            'earning_currency' => 'GNF',
            'earning_settlement_status' => null,
            'earning_settled_at' => null,
            'package_signature_required' => true,
            'package_photo_asset_id' => null,
            'proof_reception_code' => null,
            'proof_recipient_name' => null,
            'proof_signature_asset_id' => null,
            'proof_delivery_photo_asset_id' => null,
            'proof_signed_at' => null,
            'proof_photo_captured_at' => null,
            'proof_signature_bucket' => null,
            'proof_signature_object_key' => null,
            'proof_photo_bucket' => null,
            'proof_photo_object_key' => null,
        ];
    }
}
