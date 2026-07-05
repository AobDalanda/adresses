<?php

namespace App\Service;

use App\Enum\DeliveryPaymentStatus;
use App\Dto\Pricing\PricingRequest;
use App\Entity\UserAccount;
use App\Exception\NoActiveSubscriptionException;
use App\Service\Pricing\PricingEngine;
use App\Service\Subscription\PlanLimitChecker;
use App\Service\Subscription\SubscriptionManager;
use App\Service\Subscription\UsageCounterManager;
use App\Util\PhoneNumberNormalizer;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final class DeliveryCreateService
{
    private const ROAD_DISTANCE_FACTOR = 1.25;
    private const AVERAGE_DRIVING_SPEED_KMH = 20.0;

    public function __construct(
        private readonly Connection $db,
        private readonly PricingEngine $pricing,
        private readonly SubscriptionManager $subscriptions,
        private readonly PlanLimitChecker $planLimits,
        private readonly UsageCounterManager $usageCounters,
        private readonly DeliveryOrderNotificationPublisherInterface $notificationPublisher,
    ) {
    }

    /**
     * @param array{
     *     pickup: array{type: 'address'|'user_address', id: int},
     *     dropoff: array{type: 'address'|'user_address', id: int},
     *     serviceType: string,
     *     vehicleType: string,
     *     scheduledAt?: ?\DateTimeImmutable,
     *     notes?: ?string,
     *     recipient?: ?array{name?: ?string, phone?: ?string},
     *     package?: ?array{
     *         description?: ?string,
     *         declaredValueAmount?: ?float,
     *         declaredValueCurrency?: ?string,
     *         weightKg?: ?float,
     *         lengthCm?: ?float,
     *         widthCm?: ?float,
     *         heightCm?: ?float,
     *         fragile?: bool,
     *         signatureRequired?: bool,
     *         photoAssetId?: ?int
     *     }
     * } $payload
     * @return array<string, mixed>
     */
    public function create(int $userId, array $payload): array
    {
        $user = $this->subscriptions->getUser($userId);

        try {
            $subscription = $this->subscriptions->getActiveSubscription($user);
        } catch (NoActiveSubscriptionException) {
            $subscription = $this->subscriptions->initializeFreeSubscription($userId);
        }

        $this->planLimits->assertCanCreateDelivery($user);

        $pickup = $this->resolveAddressReference($payload['pickup'], 'départ');
        $dropoff = $this->resolveAddressReference($payload['dropoff'], 'destination');
        $this->assertAddressHasCoordinates($pickup, 'départ');
        $this->assertAddressHasCoordinates($dropoff, 'destination');
        $this->assertServiceTypeExists($payload['serviceType']);
        $this->assertVehicleTypeExists($payload['vehicleType']);

        $recipient = $this->normalizeRecipient($payload['recipient'] ?? null);
        $package = $this->normalizePackage($payload['package'] ?? null);

        $distanceKm = $this->isSameAddress($pickup, $dropoff)
            ? 0.0
            : $this->calculateRoadDistanceKm(
                (float) $pickup['latitude'],
                (float) $pickup['longitude'],
                (float) $dropoff['latitude'],
                (float) $dropoff['longitude']
            );
        $durationMinutes = $this->calculateDurationMinutes($distanceKm);
        $zoneId = $this->resolvePricingZoneId(
            $pickup['zone_admin_area_id'] !== null ? (int) $pickup['zone_admin_area_id'] : null,
            $pickup['zone_name'] !== null ? (string) $pickup['zone_name'] : null
        );

        $pricing = $this->pricing->calculate(new PricingRequest(
            distanceKm: $distanceKm,
            durationMinutes: $durationMinutes,
            serviceType: $payload['serviceType'],
            vehicleType: $payload['vehicleType'],
            customerType: $this->resolveCustomerType($userId),
            zoneId: $zoneId,
            date: $payload['scheduledAt'] ?? new \DateTimeImmutable()
        ));

        $publicId = Uuid::v7()->toRfc4122();

        $this->db->beginTransaction();

        try {
            $orderId = (int) $this->db->fetchOne(
                <<<'SQL'
                    INSERT INTO delivery_order (
                        public_id,
                        customer_id,
                        pickup_address_id,
                        dropoff_address_id,
                        service_type_code,
                        vehicle_type_code,
                        status,
                        scheduled_at,
                        notes,
                        recipient_name,
                        recipient_phone,
                        created_at,
                        updated_at
                    )
                    VALUES (
                        :publicId,
                        :customerId,
                        :pickupAddressId,
                        :dropoffAddressId,
                        :serviceTypeCode,
                        :vehicleTypeCode,
                        'QUOTED',
                        :scheduledAt,
                        :notes,
                        :recipientName,
                        :recipientPhone,
                        now(),
                        now()
                    )
                    RETURNING id
                    SQL,
                [
                    'publicId' => $publicId,
                    'customerId' => $userId,
                    'pickupAddressId' => (int) $pickup['address_id'],
                    'dropoffAddressId' => (int) $dropoff['address_id'],
                    'serviceTypeCode' => $this->normalizeCode($payload['serviceType']),
                    'vehicleTypeCode' => $this->normalizeCode($payload['vehicleType']),
                    'scheduledAt' => ($payload['scheduledAt'] ?? null)?->format('Y-m-d H:i:sP'),
                    'notes' => $payload['notes'] ?? null,
                    'recipientName' => $recipient['name'] ?? null,
                    'recipientPhone' => $recipient['phone'] ?? null,
                ]
            );

            if ($package !== null) {
                $this->db->executeStatement(
                    <<<'SQL'
                        INSERT INTO delivery_package (
                            delivery_order_id,
                            description,
                            declared_value_amount,
                            declared_value_currency,
                            weight_kg,
                            length_cm,
                            width_cm,
                            height_cm,
                            fragile,
                            signature_required,
                            photo_asset_id,
                            created_at
                        )
                        VALUES (
                            :deliveryOrderId,
                            :description,
                            :declaredValueAmount,
                            :declaredValueCurrency,
                            :weightKg,
                            :lengthCm,
                            :widthCm,
                            :heightCm,
                            :fragile,
                            :signatureRequired,
                            :photoAssetId,
                            now()
                        )
                        SQL,
                    [
                        'deliveryOrderId' => $orderId,
                        'description' => $package['description'] ?? null,
                        'declaredValueAmount' => $package['declaredValueAmount'] ?? null,
                        'declaredValueCurrency' => $package['declaredValueCurrency'] ?? null,
                        'weightKg' => $package['weightKg'] ?? null,
                        'lengthCm' => $package['lengthCm'] ?? null,
                        'widthCm' => $package['widthCm'] ?? null,
                        'heightCm' => $package['heightCm'] ?? null,
                        'fragile' => $package['fragile'] ?? false,
                        'signatureRequired' => $package['signatureRequired'] ?? false,
                        'photoAssetId' => $package['photoAssetId'] ?? null,
                        ]
                );

                if (($package['photoAssetId'] ?? null) !== null) {
                    $consumed = $this->db->executeStatement(
                        <<<'SQL'
                            UPDATE uploaded_asset
                            SET consumed_at = now()
                            WHERE id = :assetId
                              AND category = 'package_photo'
                              AND validation_status = 'VALID'
                              AND consumed_at IS NULL
                            SQL,
                        ['assetId' => $package['photoAssetId']]
                    );

                    if ($consumed !== 1) {
                        throw new \DomainException('La photo du colis est introuvable, invalide ou deja consommee.');
                    }
                }
            }

            $this->db->executeStatement(
                <<<'SQL'
                    INSERT INTO delivery_pricing_snapshot (
                        delivery_order_id,
                        distance_km,
                        duration_minutes,
                        zone_id,
                        customer_type_code,
                        base_amount,
                        surcharge_amount,
                        total_amount,
                        currency,
                        pricing_payload,
                        quoted_at
                    )
                    VALUES (
                        :deliveryOrderId,
                        :distanceKm,
                        :durationMinutes,
                        :zoneId,
                        :customerTypeCode,
                        :baseAmount,
                        :surchargeAmount,
                        :totalAmount,
                        :currency,
                        CAST(:pricingPayload AS jsonb),
                        now()
                    )
                    SQL,
                [
                    'deliveryOrderId' => $orderId,
                    'distanceKm' => round($distanceKm, 2),
                    'durationMinutes' => $durationMinutes,
                    'zoneId' => $zoneId,
                    'customerTypeCode' => $this->resolveCustomerType($userId),
                    'baseAmount' => $pricing->basePrice + $pricing->distancePrice,
                    'surchargeAmount' => array_sum(array_map(
                        static fn (array $surcharge): int => (int) ($surcharge['amount'] ?? 0),
                        $pricing->toArray()['surcharges']
                    )),
                    'totalAmount' => $pricing->totalPrice,
                    'currency' => $pricing->currency,
                    'pricingPayload' => json_encode($pricing->toArray(), JSON_THROW_ON_ERROR),
                ]
            );

            $this->db->executeStatement(
                <<<'SQL'
                    INSERT INTO delivery_payment (
                        delivery_order_id,
                        amount,
                        currency,
                        status,
                        payment_method,
                        provider_reference,
                        paid_at,
                        created_at,
                        updated_at
                    )
                    VALUES (
                        :deliveryOrderId,
                        :amount,
                        :currency,
                        'PENDING',
                        NULL,
                        NULL,
                        NULL,
                        now(),
                        now()
                    )
                    SQL,
                [
                    'deliveryOrderId' => $orderId,
                    'amount' => $pricing->totalPrice,
                    'currency' => $pricing->currency,
                ]
            );

            $this->db->executeStatement(
                <<<'SQL'
                    INSERT INTO delivery_status_history (
                        delivery_order_id,
                        status,
                        comment,
                        changed_by_user_id,
                        changed_by_role,
                        created_at
                    )
                    VALUES (
                        :deliveryOrderId,
                        'QUOTED',
                        :comment,
                        :changedByUserId,
                        :changedByRole,
                        now()
                    )
                    SQL,
                [
                    'deliveryOrderId' => $orderId,
                    'comment' => 'Commande créée et tarif calculé.',
                    'changedByUserId' => $userId,
                    'changedByRole' => 'CUSTOMER',
                ]
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->usageCounters->incrementDeliveriesCreated($user, $subscription);

        $createdAt = new \DateTimeImmutable();
        $delivery = [
            'id' => $publicId,
            'status' => 'QUOTED',
            'pickupAddress' => [
                'id' => (int) $pickup['address_id'],
                'displayLabel' => $pickup['address_name'] !== null ? (string) $pickup['address_name'] : null,
                'latitude' => (float) $pickup['latitude'],
                'longitude' => (float) $pickup['longitude'],
            ],
            'dropoffAddress' => [
                'id' => (int) $dropoff['address_id'],
                'displayLabel' => $dropoff['address_name'] !== null ? (string) $dropoff['address_name'] : null,
                'latitude' => (float) $dropoff['latitude'],
                'longitude' => (float) $dropoff['longitude'],
            ],
            'recipient' => $recipient,
            'package' => $package,
            'pricing' => [
                'distanceKm' => round($distanceKm, 1),
                'durationMinutes' => $durationMinutes,
                'baseAmount' => $pricing->basePrice + $pricing->distancePrice,
                'surchargeAmount' => array_sum(array_map(
                    static fn (array $surcharge): int => (int) ($surcharge['amount'] ?? 0),
                    $pricing->toArray()['surcharges']
                )),
                'totalAmount' => $pricing->totalPrice,
                'currency' => $pricing->currency,
                'details' => $pricing->toArray(),
            ],
            'payment' => [
                'amount' => $pricing->totalPrice,
                'currency' => $pricing->currency,
                'status' => DeliveryPaymentStatus::PENDING->value,
                'paidAt' => null,
                'method' => null,
            ],
            'scheduledAt' => ($payload['scheduledAt'] ?? null)?->format(\DATE_ATOM),
            'notes' => $payload['notes'] ?? null,
            'createdAt' => $createdAt->format(\DATE_ATOM),
        ];

        $this->notificationPublisher->publishNewDeliveryOrder($delivery);

        return $delivery;
    }

    /**
     * @param array{type: 'address'|'user_address', id: int} $reference
     * @return array<string, mixed>
     */
    private function resolveAddressReference(array $reference, string $label): array
    {
        return match ($reference['type']) {
            'address' => $this->findAddressById($reference['id'], $label),
            'user_address' => $this->findAddressByUserAddressId($reference['id'], $label),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function findAddressById(int $addressId, string $label = 'adresse'): array
    {
        $row = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT
                    a.id AS address_id,
                    a.display_label AS address_name,
                    ST_Y(gwl.final_geom::geometry) AS latitude,
                    ST_X(gwl.final_geom::geometry) AS longitude,
                    gaa.id AS zone_admin_area_id,
                    gaa.name AS zone_name
                FROM address a
                LEFT JOIN gps_weighted_location gwl ON gwl.id = a.weighted_location_id
                LEFT JOIN geo_admin_area gaa ON gaa.id = a.admin_area_id
                WHERE a.id = :addressId
                LIMIT 1
                SQL,
            ['addressId' => $addressId]
        );

        if ($row === false) {
            throw new \RuntimeException(sprintf('Adresse de %s introuvable (addressId=%d).', $label, $addressId));
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function findAddressByUserAddressId(int $userAddressId, string $label): array
    {
        $row = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT
                    a.id AS address_id,
                    a.display_label AS address_name,
                    ST_Y(gwl.final_geom::geometry) AS latitude,
                    ST_X(gwl.final_geom::geometry) AS longitude,
                    gaa.id AS zone_admin_area_id,
                    gaa.name AS zone_name,
                    ua.id AS user_address_id
                FROM user_address ua
                JOIN address a ON a.id = ua.address_id
                LEFT JOIN gps_weighted_location gwl ON gwl.id = a.weighted_location_id
                LEFT JOIN geo_admin_area gaa ON gaa.id = a.admin_area_id
                WHERE ua.id = :userAddressId
                LIMIT 1
                SQL,
            ['userAddressId' => $userAddressId]
        );

        if ($row === false) {
            throw new \RuntimeException(sprintf('Adresse de %s introuvable (userAddressId=%d).', $label, $userAddressId));
        }

        return $row;
    }

    private function assertAddressHasCoordinates(array $address, string $label): void
    {
        if ($address['latitude'] === null || $address['longitude'] === null) {
            $addressId = isset($address['address_id']) ? (int) $address['address_id'] : 0;
            throw new \RuntimeException(sprintf('Coordonnées GPS de %s introuvables (addressId=%d).', $label, $addressId));
        }
    }

    private function assertServiceTypeExists(string $serviceType): void
    {
        $exists = $this->db->fetchOne(
            'SELECT 1 FROM service_types WHERE code = :code AND is_active = TRUE LIMIT 1',
            ['code' => $this->normalizeCode($serviceType)]
        );

        if ($exists === false) {
            throw new \InvalidArgumentException('serviceType est invalide');
        }
    }

    private function assertVehicleTypeExists(string $vehicleType): void
    {
        $exists = $this->db->fetchOne(
            'SELECT 1 FROM vehicle_types WHERE code = :code AND is_active = TRUE LIMIT 1',
            ['code' => $this->normalizeCode($vehicleType)]
        );

        if ($exists === false) {
            throw new \InvalidArgumentException('vehicleType est invalide');
        }
    }

    /**
     * @param array{name?: ?string, phone?: ?string}|null $recipient
     * @return array{name?: ?string, phone?: ?string}|null
     */
    private function normalizeRecipient(?array $recipient): ?array
    {
        if ($recipient === null) {
            return null;
        }

        $phone = $recipient['phone'] ?? null;
        if (is_string($phone) && trim($phone) !== '') {
            $normalized = PhoneNumberNormalizer::normalize($phone);
            if ($normalized === '') {
                throw new \InvalidArgumentException('recipient.phone est invalide');
            }
            $recipient['phone'] = $normalized;
        }

        return $recipient;
    }

    /**
     * @param array<string, mixed>|null $package
     * @return array<string, mixed>|null
     */
    private function normalizePackage(?array $package): ?array
    {
        if ($package === null) {
            return null;
        }

        $photoAssetId = $package['photoAssetId'] ?? null;
        if (is_int($photoAssetId)) {
            $asset = $this->db->fetchAssociative(
                'SELECT id, category, consumed_at, validation_status FROM uploaded_asset WHERE id = :id LIMIT 1',
                ['id' => $photoAssetId]
            );

            if (
                $asset === false
                || (string) $asset['category'] !== 'package_photo'
                || (string) $asset['validation_status'] !== 'VALID'
                || $asset['consumed_at'] !== null
            ) {
                throw new \InvalidArgumentException('package.photoAssetId est invalide');
            }
        }

        return $package;
    }

    private function isSameAddress(array $pickup, array $dropoff): bool
    {
        return (int) $pickup['address_id'] === (int) $dropoff['address_id'];
    }

    private function calculateRoadDistanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;
        $centralAngle = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $centralAngle * self::ROAD_DISTANCE_FACTOR;
    }

    private function calculateDurationMinutes(float $distanceKm): int
    {
        return max(1, (int) ceil(($distanceKm / self::AVERAGE_DRIVING_SPEED_KMH) * 60));
    }

    private function resolvePricingZoneId(?int $adminAreaId, ?string $zoneName): ?int
    {
        if ($adminAreaId !== null) {
            $zoneId = $this->db->fetchOne(
                'SELECT id FROM zones WHERE admin_area_id = :adminAreaId LIMIT 1',
                ['adminAreaId' => $adminAreaId]
            );

            if ($zoneId !== false) {
                return (int) $zoneId;
            }
        }

        if ($zoneName !== null && trim($zoneName) !== '') {
            $zoneId = $this->db->fetchOne(
                'SELECT id FROM zones WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name)) ORDER BY id DESC LIMIT 1',
                ['name' => $zoneName]
            );

            if ($zoneId !== false) {
                return (int) $zoneId;
            }
        }

        return null;
    }

    private function resolveCustomerType(int $userId): string
    {
        $isProvider = (bool) $this->db->fetchOne(
            'SELECT EXISTS(SELECT 1 FROM provider_profile WHERE user_id = :userId)',
            ['userId' => $userId]
        );
        if ($isProvider) {
            return 'PROVIDER';
        }

        $accountType = $this->db->fetchOne(
            'SELECT account_type FROM user_account WHERE id = :userId LIMIT 1',
            ['userId' => $userId]
        );

        $normalized = is_string($accountType) ? $this->normalizeCode($accountType) : '';

        return $normalized === 'BUSINESS' ? 'BUSINESS' : 'CLIENT';
    }

    private function normalizeCode(string $code): string
    {
        $normalized = strtoupper(trim($code));
        $normalized = strtr($normalized, [
            'É' => 'E',
            'È' => 'E',
            'Ê' => 'E',
            'À' => 'A',
            'Ù' => 'U',
            'Ç' => 'C',
        ]);

        return preg_replace('/[^A-Z0-9]+/', '_', $normalized) ?? $normalized;
    }
}
