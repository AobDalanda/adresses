<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

final readonly class DeliveryOrderRepository
{
    public function __construct(private Connection $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findOffer(string $publicId, bool $forUpdate = false): ?array
    {
        $row = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT
                    delivery.id, delivery.public_id, delivery.status,
                    delivery.assigned_driver_id, delivery.service_type_code,
                    delivery.vehicle_type_code, delivery.notes,
                    delivery.recipient_name, delivery.recipient_phone,
                    pickup.id AS pickup_address_id,
                    pickup.display_label AS pickup_name,
                    pickup.address_code AS pickup_address,
                    pickup.contact_phone AS pickup_contact_phone,
                    pickup.country_code AS pickup_country_code,
                    pickup_area.name AS pickup_zone,
                    ST_Y(pickup_location.final_geom::geometry) AS pickup_latitude,
                    ST_X(pickup_location.final_geom::geometry) AS pickup_longitude,
                    dropoff.id AS dropoff_address_id,
                    dropoff.display_label AS dropoff_name,
                    dropoff.address_code AS dropoff_address,
                    ST_Y(dropoff_location.final_geom::geometry) AS dropoff_latitude,
                    ST_X(dropoff_location.final_geom::geometry) AS dropoff_longitude,
                    package.description AS package_description,
                    pricing.total_amount, pricing.base_amount, pricing.surcharge_amount,
                    pricing.currency, pricing.distance_km, pricing.duration_minutes,
                    earning.estimated_amount AS driver_earning
                FROM delivery_order delivery
                JOIN address pickup ON pickup.id = delivery.pickup_address_id
                JOIN address dropoff ON dropoff.id = delivery.dropoff_address_id
                LEFT JOIN geo_admin_area pickup_area ON pickup_area.id = pickup.admin_area_id
                LEFT JOIN gps_weighted_location pickup_location ON pickup_location.id = pickup.weighted_location_id
                LEFT JOIN gps_weighted_location dropoff_location ON dropoff_location.id = dropoff.weighted_location_id
                LEFT JOIN delivery_package package ON package.delivery_order_id = delivery.id
                LEFT JOIN delivery_pricing_snapshot pricing ON pricing.delivery_order_id = delivery.id
                LEFT JOIN delivery_driver_earning earning ON earning.delivery_order_id = delivery.id
                WHERE delivery.public_id = :publicId
                LIMIT 1
                SQL.($forUpdate ? ' FOR UPDATE OF delivery' : ''),
            ['publicId' => $publicId],
        );

        return $row === false ? null : $row;
    }
}
