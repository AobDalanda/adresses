<?php

namespace App\Service;

use App\Util\PhoneNumberNormalizer;
use App\Dto\Pricing\PricingRequest;
use App\Service\Pricing\PricingEngine;
use Doctrine\DBAL\Connection;

class DeliveryQuoteService
{
    private const ROAD_DISTANCE_FACTOR = 1.25;
    private const AVERAGE_DRIVING_SPEED_KMH = 20.0;

    public function __construct(
        private Connection $db,
        private PricingEngine $pricing
    )
    {
    }

    /**
     * @param array{addressName: string, userIdentifier: int|string} $departureInput
     * @param string|array{addressName: string, userIdentifier: int|string} $destinationInput
     * @return array<string, mixed>
     */
    public function quote(
        array $departureInput,
        string|array $destinationInput,
        string $serviceType = 'STANDARD',
        string $vehicleType = 'MOTO',
        ?int $requesterUserId = null,
        ?\DateTimeImmutable $date = null
    ): array
    {
        $departure = $this->findAddressByNameAndUser(
            $departureInput['addressName'],
            $this->decodeUserIdentifier($departureInput['userIdentifier'])
        );
        if ($departure === null) {
            throw new \RuntimeException('Adresse de départ introuvable.');
        }

        $destination = is_string($destinationInput)
            ? $this->findAddressByQrToken($destinationInput)
            : $this->findAddressByNameAndUser(
                $destinationInput['addressName'],
                $this->decodeUserIdentifier($destinationInput['userIdentifier'])
            );

        if ($destination === null) {
            throw new \RuntimeException('Adresse de destination introuvable.');
        }

        $this->assertAddressHasCoordinates($departure, 'départ');
        $this->assertAddressHasCoordinates($destination, 'destination');

        $distanceKm = $this->calculateRoadDistanceKm(
            (float) $departure['latitude'],
            (float) $departure['longitude'],
            (float) $destination['latitude'],
            (float) $destination['longitude']
        );
        $durationMinutes = $this->calculateDurationMinutes($distanceKm);
        $zoneId = $this->resolvePricingZoneId(
            $departure['zone_admin_area_id'] !== null ? (int) $departure['zone_admin_area_id'] : null,
            $departure['zone_name'] !== null ? (string) $departure['zone_name'] : null
        );
        $customerType = $this->resolveCustomerType($requesterUserId);
        $pricing = $this->pricing->calculate(new PricingRequest(
            distanceKm: $distanceKm,
            durationMinutes: $durationMinutes,
            serviceType: $serviceType,
            vehicleType: $vehicleType,
            customerType: $customerType,
            zoneId: $zoneId,
            date: $date ?? new \DateTimeImmutable()
        ));

        return [
            'recipient' => $this->recipientPayload($destination),
            'departure' => [
                'latitude' => (float) $departure['latitude'],
                'longitude' => (float) $departure['longitude'],
            ],
            'destination' => [
                'latitude' => (float) $destination['latitude'],
                'longitude' => (float) $destination['longitude'],
            ],
            'distanceKm' => round($distanceKm, 1),
            'durationMinutes' => $durationMinutes,
            'serviceType' => $this->normalizeCode($serviceType),
            'vehicleType' => $this->normalizeCode($vehicleType),
            'pricing' => $pricing->toArray(),
            'deliveryCost' => $pricing->totalPrice,
            'currency' => $pricing->currency,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findAddressByNameAndUser(string $addressName, int $userId): ?array
    {
        $row = $this->db->fetchAssociative(
            '
            SELECT
                a.id AS address_id,
                a.display_label AS address_name,
                ST_Y(gwl.final_geom::geometry) AS latitude,
                ST_X(gwl.final_geom::geometry) AS longitude,
                gaa.id AS zone_admin_area_id,
                gaa.name AS zone_name,
                u.id AS user_id,
                u.name AS user_name,
                u.phone AS user_phone
            FROM user_address ua
            JOIN address a ON a.id = ua.address_id
            JOIN user_account u ON u.id = ua.user_id
            LEFT JOIN gps_weighted_location gwl ON gwl.id = a.weighted_location_id
            LEFT JOIN geo_admin_area gaa ON gaa.id = a.admin_area_id
            WHERE ua.user_id = :userId
              AND LOWER(TRIM(a.display_label)) = LOWER(TRIM(:addressName))
            ORDER BY ua.is_primary DESC, ua.id DESC
            LIMIT 1
            ',
            [
                'userId' => $userId,
                'addressName' => $addressName,
            ]
        );

        return $row !== false ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findAddressByQrToken(string $token): ?array
    {
        $row = $this->db->fetchAssociative(
            '
            SELECT
                a.id AS address_id,
                a.display_label AS address_name,
                ST_Y(gwl.final_geom::geometry) AS latitude,
                ST_X(gwl.final_geom::geometry) AS longitude,
                gaa.id AS zone_admin_area_id,
                gaa.name AS zone_name,
                u.id AS user_id,
                u.name AS user_name,
                u.phone AS user_phone
            FROM address_qrcodes aq
            JOIN address a ON a.id = aq.address_id
            JOIN user_account u ON u.id = aq.created_by
            LEFT JOIN gps_weighted_location gwl ON gwl.id = a.weighted_location_id
            LEFT JOIN geo_admin_area gaa ON gaa.id = a.admin_area_id
            WHERE aq.token = :token
              AND aq.is_active = true
              AND aq.revoked_at IS NULL
              AND (aq.expires_at IS NULL OR aq.expires_at > now())
              AND (aq.max_scans IS NULL OR aq.current_scans < aq.max_scans)
            LIMIT 1
            ',
            ['token' => trim($token)]
        );

        return $row !== false ? $row : null;
    }

    private function decodeUserIdentifier(int|string $identifier): int
    {
        if (is_int($identifier)) {
            if ($identifier > 0) {
                return $identifier;
            }

            throw new \InvalidArgumentException('userIdentifier invalide.');
        }

        $identifier = trim($identifier);
        if (preg_match('/^(?:USR|USER)[_:-](\d+)$/i', $identifier, $matches) === 1) {
            return (int) $matches[1];
        }

        if (ctype_digit($identifier)) {
            return (int) $identifier;
        }

        $normalizedPhone = PhoneNumberNormalizer::normalize($identifier);
        if ($normalizedPhone !== '') {
            $userId = $this->db->fetchOne(
                'SELECT id FROM user_account WHERE phone = :phone LIMIT 1',
                ['phone' => $normalizedPhone]
            );

            if ($userId !== false) {
                return (int) $userId;
            }
        }

        throw new \InvalidArgumentException('userIdentifier invalide.');
    }

    /**
     * @param array<string, mixed> $address
     */
    private function assertAddressHasCoordinates(array $address, string $label): void
    {
        if ($address['latitude'] === null || $address['longitude'] === null) {
            throw new \RuntimeException(sprintf('Coordonnées GPS de %s introuvables.', $label));
        }
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

    private function resolveCustomerType(?int $requesterUserId): string
    {
        if ($requesterUserId === null || $requesterUserId <= 0) {
            return 'CLIENT';
        }

        $accountType = $this->db->fetchOne(
            'SELECT account_type FROM user_account WHERE id = :userId LIMIT 1',
            ['userId' => $requesterUserId]
        );

        if (!is_string($accountType) || trim($accountType) === '') {
            return 'CLIENT';
        }

        return $this->normalizeCode($accountType);
    }

    /**
     * @param array<string, mixed> $address
     * @return array{id: string, firstName: ?string, lastName: ?string, phone: string}
     */
    private function recipientPayload(array $address): array
    {
        [$firstName, $lastName] = $this->splitName($address['user_name'] !== null ? (string) $address['user_name'] : null);

        return [
            'id' => sprintf('USR_%d', (int) $address['user_id']),
            'firstName' => $firstName,
            'lastName' => $lastName,
            'phone' => (string) $address['user_phone'],
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function splitName(?string $name): array
    {
        if ($name === null || trim($name) === '') {
            return [null, null];
        }

        $parts = preg_split('/\s+/', trim($name), 2);
        if ($parts === false) {
            return [$name, null];
        }

        return [
            $parts[0] ?? null,
            $parts[1] ?? null,
        ];
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
