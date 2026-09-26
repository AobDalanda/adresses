<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\DeliveryGeographyException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class DeliveryGeographyPolicyService implements DeliveryGeographyPolicyInterface
{
    public const MODES = ['DOMESTIC_ONLY', 'SAME_COUNTRY', 'INTERNATIONAL'];

    public function __construct(private Connection $db)
    {
    }

    /** @return array{mode: string, allowedCountryCodes: list<string>, requireSameCountry: bool, enabled: bool, updatedBy: ?int, updatedAt: string} */
    public function get(): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT mode, allowed_country_codes, require_same_country, enabled, updated_by, updated_at FROM delivery_geography_policy WHERE id = 1'
        );

        if ($row === false) {
            return [
                'mode' => 'DOMESTIC_ONLY',
                'allowedCountryCodes' => ['FR', 'GN'],
                'requireSameCountry' => true,
                'enabled' => true,
                'updatedBy' => null,
                'updatedAt' => (new \DateTimeImmutable('@0'))->format(\DateTimeInterface::ATOM),
            ];
        }

        return $this->normalizeRow($row);
    }

    /** @param array<string, mixed> $payload */
    public function update(array $payload, int $adminUserId): array
    {
        $current = $this->get();
        $mode = array_key_exists('mode', $payload) ? $this->normalizeMode($payload['mode']) : $current['mode'];
        $countries = array_key_exists('allowedCountryCodes', $payload)
            ? $this->normalizeCountries($payload['allowedCountryCodes'])
            : $current['allowedCountryCodes'];
        $requireSameCountry = array_key_exists('requireSameCountry', $payload)
            ? $this->requireBool($payload['requireSameCountry'], 'requireSameCountry')
            : $current['requireSameCountry'];
        $enabled = array_key_exists('enabled', $payload)
            ? $this->requireBool($payload['enabled'], 'enabled')
            : $current['enabled'];

        if ($mode === 'DOMESTIC_ONLY' && $countries === []) {
            throw new \InvalidArgumentException('allowedCountryCodes ne peut pas être vide en mode DOMESTIC_ONLY.');
        }

        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO delivery_geography_policy (
                    id, mode, allowed_country_codes, require_same_country, enabled, updated_by, updated_at
                ) VALUES (1, :mode, CAST(:countries AS jsonb), :sameCountry, :enabled, :updatedBy, now())
                ON CONFLICT (id) DO UPDATE SET
                    mode = EXCLUDED.mode,
                    allowed_country_codes = EXCLUDED.allowed_country_codes,
                    require_same_country = EXCLUDED.require_same_country,
                    enabled = EXCLUDED.enabled,
                    updated_by = EXCLUDED.updated_by,
                    updated_at = EXCLUDED.updated_at
                SQL,
            [
                'mode' => $mode,
                'countries' => json_encode($countries, JSON_THROW_ON_ERROR),
                'sameCountry' => $requireSameCountry,
                'enabled' => $enabled,
                'updatedBy' => $adminUserId,
            ],
            ['sameCountry' => ParameterType::BOOLEAN, 'enabled' => ParameterType::BOOLEAN],
        );

        $newValue = [
            'mode' => $mode,
            'allowedCountryCodes' => $countries,
            'requireSameCountry' => $requireSameCountry,
            'enabled' => $enabled,
        ];
        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO delivery_geography_policy_history (
                    changed_by, previous_value, new_value, changed_at
                ) VALUES (:changedBy, CAST(:previous AS jsonb), CAST(:new AS jsonb), now())
                SQL,
            [
                'changedBy' => $adminUserId,
                'previous' => json_encode($current, JSON_THROW_ON_ERROR),
                'new' => json_encode($newValue, JSON_THROW_ON_ERROR),
            ],
        );

        return $this->get();
    }

    public function assertDeliveryAllowed(?string $pickupCountry, ?string $dropoffCountry): void
    {
        $policy = $this->get();
        if (!$policy['enabled']) {
            return;
        }

        $pickup = $this->normalizeCountry($pickupCountry);
        $dropoff = $this->normalizeCountry($dropoffCountry);
        if ($pickup === null || $dropoff === null) {
            throw new DeliveryGeographyException(
                'DELIVERY_COUNTRY_UNKNOWN',
                'Le pays de l’adresse de départ ou de destination est inconnu.',
                $policy['allowedCountryCodes'],
            );
        }

        if ($policy['mode'] === 'DOMESTIC_ONLY') {
            if (!in_array($pickup, $policy['allowedCountryCodes'], true) || !in_array($dropoff, $policy['allowedCountryCodes'], true)) {
                throw new DeliveryGeographyException(
                    'DELIVERY_COUNTRY_NOT_ALLOWED',
                    'Une adresse se trouve dans un pays où les livraisons ne sont pas autorisées.',
                    $policy['allowedCountryCodes'],
                );
            }
        }

        if (($policy['mode'] === 'SAME_COUNTRY' || $policy['requireSameCountry']) && $pickup !== $dropoff) {
            throw new DeliveryGeographyException(
                'CROSS_BORDER_DELIVERY_NOT_ALLOWED',
                'L’adresse de départ et l’adresse de destination doivent être dans le même pays.',
                $policy['allowedCountryCodes'],
            );
        }
    }

    private function normalizeMode(mixed $mode): string
    {
        if (!is_string($mode) || !in_array(strtoupper(trim($mode)), self::MODES, true)) {
            throw new \InvalidArgumentException('mode doit être DOMESTIC_ONLY, SAME_COUNTRY ou INTERNATIONAL.');
        }

        return strtoupper(trim($mode));
    }

    /** @return list<string> */
    private function normalizeCountries(mixed $countries): array
    {
        if (!is_array($countries)) {
            throw new \InvalidArgumentException('allowedCountryCodes doit être un tableau.');
        }

        $normalized = [];
        foreach ($countries as $country) {
            $code = $this->normalizeCountry($country);
            if ($code === null) {
                throw new \InvalidArgumentException('Chaque pays doit être un code ISO 3166-1 alpha-2.');
            }
            $normalized[] = $code;
        }

        sort($normalized);

        return array_values(array_unique($normalized));
    }

    private function normalizeCountry(mixed $country): ?string
    {
        if (!is_string($country)) {
            return null;
        }
        $country = strtoupper(trim($country));

        return preg_match('/^[A-Z]{2}$/D', $country) === 1 ? $country : null;
    }

    private function requireBool(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw new \InvalidArgumentException(sprintf('%s doit être un booléen.', $field));
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function normalizeRow(array $row): array
    {
        $countries = is_string($row['allowed_country_codes'] ?? null)
            ? json_decode($row['allowed_country_codes'], true, 512, JSON_THROW_ON_ERROR)
            : $row['allowed_country_codes'];

        return [
            'mode' => (string) $row['mode'],
            'allowedCountryCodes' => $this->normalizeCountries($countries),
            'requireSameCountry' => $this->toBool($row['require_same_country']),
            'enabled' => $this->toBool($row['enabled']),
            'updatedBy' => $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
            'updatedAt' => (new \DateTimeImmutable((string) $row['updated_at']))->format(\DateTimeInterface::ATOM),
        ];
    }

    private function toBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}
