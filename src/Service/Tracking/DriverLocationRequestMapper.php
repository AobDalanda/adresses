<?php

declare(strict_types=1);

namespace App\Service\Tracking;

use App\Dto\Tracking\DriverLocationInput;
use App\Dto\Tracking\LocationHistoryQuery;
use Symfony\Component\HttpFoundation\Request;

final class DriverLocationRequestMapper
{
    public function mapLocation(Request $request): DriverLocationInput
    {
        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Invalid JSON body', previous: $exception);
        }

        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid JSON body');
        }

        return new DriverLocationInput(
            $this->requiredInt($payload, 'driverId'),
            $this->requiredFloat($payload, 'latitude'),
            $this->requiredFloat($payload, 'longitude'),
            $this->requiredFloat($payload, 'accuracy'),
            $this->optionalFloat($payload, 'speed'),
            $this->optionalFloat($payload, 'heading'),
            $this->optionalInt($payload, 'batteryLevel'),
            $this->optionalString($payload, 'source') ?? 'gps'
        );
    }

    public function mapHistory(Request $request): LocationHistoryQuery
    {
        $from = $this->parseDate($request->query->get('from'), false);
        $to = $this->parseDate($request->query->get('to'), true);
        $limit = $request->query->get('limit', '100');

        if (filter_var($limit, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException('limit doit etre un entier');
        }

        if ($from !== null && $to !== null && $from > $to) {
            throw new \InvalidArgumentException('from doit preceder to');
        }

        return new LocationHistoryQuery($from, $to, (int) $limit);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredInt(array $payload, string $field): int
    {
        if (!isset($payload[$field]) || filter_var($payload[$field], FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException(sprintf('%s doit etre un entier', $field));
        }

        return (int) $payload[$field];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredFloat(array $payload, string $field): float
    {
        if (!isset($payload[$field]) || !is_numeric($payload[$field])) {
            throw new \InvalidArgumentException(sprintf('%s doit etre numerique', $field));
        }

        return (float) $payload[$field];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function optionalFloat(array $payload, string $field): ?float
    {
        if (!array_key_exists($field, $payload) || $payload[$field] === null) {
            return null;
        }
        if (!is_numeric($payload[$field])) {
            throw new \InvalidArgumentException(sprintf('%s doit etre numerique', $field));
        }

        return (float) $payload[$field];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function optionalInt(array $payload, string $field): ?int
    {
        if (!array_key_exists($field, $payload) || $payload[$field] === null) {
            return null;
        }
        if (filter_var($payload[$field], FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException(sprintf('%s doit etre un entier', $field));
        }

        return (int) $payload[$field];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function optionalString(array $payload, string $field): ?string
    {
        if (!array_key_exists($field, $payload) || $payload[$field] === null) {
            return null;
        }
        if (!is_string($payload[$field])) {
            throw new \InvalidArgumentException(sprintf('%s doit etre une chaine', $field));
        }

        return trim($payload[$field]);
    }

    private function parseDate(mixed $value, bool $endOfDay): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Date invalide');
        }

        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw new \InvalidArgumentException(sprintf('Date invalide: %s', $value), previous: $exception);
        }

        if ($endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $date->setTime(23, 59, 59, 999999);
        }

        return $date;
    }
}
