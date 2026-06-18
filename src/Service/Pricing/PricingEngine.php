<?php

namespace App\Service\Pricing;

use App\Dto\Pricing\PricingRequest;
use App\Dto\Pricing\PricingResult;
use App\Dto\Pricing\PricingSurchargeResult;
use Doctrine\DBAL\Connection;

class PricingEngine
{
    private const CURRENCY = 'GNF';

    public function __construct(private Connection $db)
    {
    }

    public function calculate(PricingRequest $request): PricingResult
    {
        $model = $this->activePricingModel($request->date);
        $rule = $this->matchingRule($model, $request);

        $basePrice = (int) $rule['base_price'];
        $distancePrice = (int) round($request->distanceKm * (int) $rule['price_per_km']);
        $subtotal = $basePrice + $distancePrice;
        $surcharges = $this->matchingSurcharges((int) $model['id'], $request, $subtotal);
        $surchargeTotal = array_sum(array_map(
            static fn (PricingSurchargeResult $surcharge): int => $surcharge->amount,
            $surcharges
        ));

        return new PricingResult(
            distance: round($request->distanceKm, 1),
            duration: $request->durationMinutes,
            basePrice: $basePrice,
            distancePrice: $distancePrice,
            surcharges: $surcharges,
            totalPrice: $subtotal + $surchargeTotal,
            currency: self::CURRENCY,
            pricingModelId: (int) $model['id'],
            pricingRuleId: (int) $rule['id']
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function activePricingModel(\DateTimeImmutable $date): array
    {
        $model = $this->db->fetchAssociative(
            '
            SELECT id, name
            FROM pricing_models
            WHERE is_active = TRUE
              AND valid_from <= :date
              AND (valid_to IS NULL OR valid_to > :date)
            ORDER BY valid_from DESC, id DESC
            LIMIT 1
            ',
            ['date' => $date->format('Y-m-d H:i:sP')]
        );

        if ($model === false) {
            throw new \RuntimeException('Aucun modèle tarifaire actif.');
        }

        return $model;
    }

    /**
     * @param array<string, mixed> $model
     * @return array<string, mixed>
     */
    private function matchingRule(array $model, PricingRequest $request): array
    {
        $rule = $this->db->fetchAssociative(
            '
            SELECT rule.*
            FROM pricing_rules rule
            JOIN service_types service ON service.id = rule.service_type_id
            JOIN vehicle_types vehicle ON vehicle.id = rule.vehicle_type_id
            WHERE rule.pricing_model_id = :modelId
              AND rule.is_active = TRUE
              AND service.is_active = TRUE
              AND vehicle.is_active = TRUE
              AND service.code = :serviceType
              AND vehicle.code = :vehicleType
              AND rule.distance_min <= :distance
              AND (rule.distance_max IS NULL OR rule.distance_max > :distance)
              AND (rule.zone_id = :zoneId OR rule.zone_id IS NULL)
            ORDER BY
              CASE WHEN rule.zone_id = :zoneId THEN 0 ELSE 1 END,
              rule.priority DESC,
              rule.distance_min DESC,
              rule.id DESC
            LIMIT 1
            ',
            [
                'modelId' => (int) $model['id'],
                'serviceType' => $this->normalizeCode($request->serviceType),
                'vehicleType' => $this->normalizeCode($request->vehicleType),
                'distance' => $request->distanceKm,
                'zoneId' => $request->zoneId,
            ]
        );

        if ($rule === false) {
            throw new \RuntimeException('Aucune règle tarifaire applicable.');
        }

        return $rule;
    }

    /**
     * @return list<PricingSurchargeResult>
     */
    private function matchingSurcharges(int $modelId, PricingRequest $request, int $subtotal): array
    {
        $rows = $this->db->fetchAllAssociative(
            '
            SELECT name, type, value, condition_json
            FROM pricing_surcharges
            WHERE pricing_model_id = :modelId
              AND is_active = TRUE
            ORDER BY id ASC
            ',
            ['modelId' => $modelId]
        );

        $results = [];
        foreach ($rows as $row) {
            $condition = json_decode((string) $row['condition_json'], true);
            if (!is_array($condition) || !$this->conditionMatches($condition, $request)) {
                continue;
            }

            $type = (string) $row['type'];
            $value = (float) $row['value'];
            $amount = $type === 'percentage'
                ? (int) round($subtotal * ($value / 100))
                : (int) round($value);

            $results[] = new PricingSurchargeResult(
                name: (string) $row['name'],
                type: $type,
                value: $value,
                amount: $amount
            );
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function conditionMatches(array $condition, PricingRequest $request): bool
    {
        if (isset($condition['service_type']) && $this->normalizeCode((string) $condition['service_type']) !== $this->normalizeCode($request->serviceType)) {
            return false;
        }

        if (isset($condition['vehicle_type']) && $this->normalizeCode((string) $condition['vehicle_type']) !== $this->normalizeCode($request->vehicleType)) {
            return false;
        }

        if (isset($condition['distance_min']) && $request->distanceKm < (float) $condition['distance_min']) {
            return false;
        }

        if (isset($condition['distance_max']) && $request->distanceKm >= (float) $condition['distance_max']) {
            return false;
        }

        if (isset($condition['zone_id']) && $request->zoneId !== (int) $condition['zone_id']) {
            return false;
        }

        return true;
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
