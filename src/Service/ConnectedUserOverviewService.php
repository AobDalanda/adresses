<?php

namespace App\Service;

use App\Util\AddressQrCodec;
use Doctrine\DBAL\Connection;

final class ConnectedUserOverviewService
{
    public function __construct(
        private Connection $db,
        private UserAccountAssetUrlResolver $assetUrlResolver,
        private SubscriptionService $subscriptions
    ) {
    }

    /**
     * @return array{
     *     profile: array<string, mixed>,
     *     addresses: list<array<string, mixed>>,
     *     orders: list<array<string, mixed>>,
     *     subscription: array<string, mixed>|null
     * }
     */
    public function getOverview(int $userId): array
    {
        $user = $this->db->fetchAssociative(
            '
            SELECT id, phone, name, email, verified, account_type, profile_photo_path, identity_document_path, identity_document_number, driver_license_path, created_at
            FROM user_account
            WHERE id = :id
            LIMIT 1
            ',
            ['id' => $userId]
        );

        if ($user === false) {
            throw new \RuntimeException('Utilisateur introuvable');
        }

        $addresses = $this->db->fetchAllAssociative(
            '
            SELECT
                ua.id AS user_address_id,
                ua.is_primary,
                a.id AS address_id,
                a.address_code,
                a.display_label,
                av.reason AS description,
                a.phone_display,
                a.contact_phone,
                a.created_at,
                ST_Y(gwl.final_geom::geometry) AS latitude,
                ST_X(gwl.final_geom::geometry) AS longitude,
                gaa.id AS admin_area_id,
                gaa.name AS admin_area_name,
                gaa.type AS admin_area_type
            FROM user_address ua
            JOIN address a ON a.id = ua.address_id
            LEFT JOIN gps_weighted_location gwl ON gwl.id = a.weighted_location_id
            LEFT JOIN geo_admin_area gaa ON gaa.id = a.admin_area_id
            LEFT JOIN LATERAL (
                SELECT reason
                FROM address_version av
                WHERE av.address_id = a.id
                ORDER BY av.versioned_at DESC, av.id DESC
                LIMIT 1
            ) av ON true
            WHERE ua.user_id = :userId
            ORDER BY ua.is_primary DESC, ua.id DESC
            ',
            ['userId' => $userId]
        );

        $orders = $this->db->fetchAllAssociative(
            '
            SELECT
                pe.id,
                pe.provider,
                pe.provider_ref,
                pe.status,
                pe.amount_cents,
                pe.currency,
                pe.created_at,
                sp.code AS plan_code,
                sp.name AS plan_name
            FROM payment_event pe
            LEFT JOIN subscription_plan sp ON sp.id = pe.plan_id
            WHERE pe.owner_type = :ownerType
              AND pe.owner_id = :ownerId
            ORDER BY pe.created_at DESC, pe.id DESC
            ',
            [
                'ownerType' => 'USER',
                'ownerId' => $userId,
            ]
        );

        $subscription = $this->subscriptions->getActiveSubscription('USER', $userId);

        return [
            'profile' => $this->assetUrlResolver->enrich([
                'id' => (int) $user['id'],
                'phone' => (string) $user['phone'],
                'name' => $user['name'],
                'email' => isset($user['email']) && is_string($user['email']) && $user['email'] !== '' ? $user['email'] : null,
                'verified' => (bool) $user['verified'],
                'accountType' => (string) $user['account_type'],
                'profilePhotoPath' => isset($user['profile_photo_path']) ? (string) $user['profile_photo_path'] : null,
                'identityDocumentPath' => isset($user['identity_document_path']) ? (string) $user['identity_document_path'] : null,
                'identityDocumentNumber' => isset($user['identity_document_number']) && is_string($user['identity_document_number']) && $user['identity_document_number'] !== '' ? $user['identity_document_number'] : null,
                'driverLicensePath' => isset($user['driver_license_path']) ? (string) $user['driver_license_path'] : null,
                'createdAt' => $user['created_at'],
            ]),
            'addresses' => array_map(
                static fn (array $row): array => [
                    'userAddressId' => (int) $row['user_address_id'],
                    'addressId' => (int) $row['address_id'],
                    'identifier' => AddressQrCodec::encode((int) $row['address_id']),
                    'addressCode' => (string) $row['address_code'],
                    'displayLabel' => $row['display_label'] !== null ? (string) $row['display_label'] : null,
                    'description' => $row['description'] !== null ? (string) $row['description'] : null,
                    'phoneDisplay' => $row['phone_display'] !== null ? (string) $row['phone_display'] : null,
                    'contactPhone' => $row['contact_phone'] !== null ? (string) $row['contact_phone'] : null,
                    'location' => [
                        'latitude' => $row['latitude'] !== null ? (float) $row['latitude'] : null,
                        'longitude' => $row['longitude'] !== null ? (float) $row['longitude'] : null,
                    ],
                    'adminArea' => $row['admin_area_id'] !== null ? [
                        'id' => (int) $row['admin_area_id'],
                        'name' => (string) $row['admin_area_name'],
                        'type' => (string) $row['admin_area_type'],
                    ] : null,
                    'isPrimary' => (bool) $row['is_primary'],
                    'createdAt' => (string) $row['created_at'],
                ],
                $addresses
            ),
            'orders' => array_map(
                static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'provider' => (string) $row['provider'],
                    'providerRef' => $row['provider_ref'] !== null ? (string) $row['provider_ref'] : null,
                    'status' => (string) $row['status'],
                    'amountCents' => (int) $row['amount_cents'],
                    'currency' => (string) $row['currency'],
                    'planCode' => $row['plan_code'] !== null ? (string) $row['plan_code'] : null,
                    'planName' => $row['plan_name'] !== null ? (string) $row['plan_name'] : null,
                    'createdAt' => (string) $row['created_at'],
                ],
                $orders
            ),
            'subscription' => $subscription !== null ? [
                'planCode' => (string) $subscription['plan_code'],
                'planName' => (string) $subscription['plan_name'],
                'priceCents' => (int) $subscription['price_cents'],
                'currency' => (string) $subscription['currency'],
                'status' => (string) $subscription['status'],
                'currentPeriodStart' => $subscription['current_period_start'],
                'currentPeriodEnd' => $subscription['current_period_end'],
                'quotaCreate' => $subscription['quota_create'] !== null ? (int) $subscription['quota_create'] : null,
                'quotaLookup' => $subscription['quota_lookup'] !== null ? (int) $subscription['quota_lookup'] : null,
            ] : null,
        ];
    }
}
