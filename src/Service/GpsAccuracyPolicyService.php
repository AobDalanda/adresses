<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

final readonly class GpsAccuracyPolicyService
{
    public const MIN_ACCURACY_METERS = 1;
    public const MAX_ACCURACY_METERS = 500;

    public function __construct(
        private Connection $db,
        private int $defaultAccuracyMeters = 5,
    ) {
    }

    /** @return array{targetAccuracyMeters: int, updatedBy: ?int, updatedAt: ?string} */
    public function get(): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT target_accuracy_meters, updated_by, updated_at FROM gps_accuracy_policy WHERE id = 1'
        );

        if ($row === false) {
            return [
                'targetAccuracyMeters' => $this->defaultAccuracyMeters,
                'updatedBy' => null,
                'updatedAt' => null,
            ];
        }

        return [
            'targetAccuracyMeters' => (int) $row['target_accuracy_meters'],
            'updatedBy' => $row['updated_by'] === null ? null : (int) $row['updated_by'],
            'updatedAt' => (new \DateTimeImmutable((string) $row['updated_at']))->format(\DateTimeInterface::ATOM),
        ];
    }

    public function targetAccuracyMeters(): int
    {
        return $this->get()['targetAccuracyMeters'];
    }

    /** @return array{targetAccuracyMeters: int, updatedBy: ?int, updatedAt: ?string} */
    public function update(mixed $value, int $adminUserId): array
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new \InvalidArgumentException('targetAccuracyMeters doit être un nombre entier.');
        }

        $accuracy = (int) $value;
        if ($accuracy < self::MIN_ACCURACY_METERS || $accuracy > self::MAX_ACCURACY_METERS) {
            throw new \InvalidArgumentException(sprintf(
                'targetAccuracyMeters doit être compris entre %d et %d mètres.',
                self::MIN_ACCURACY_METERS,
                self::MAX_ACCURACY_METERS,
            ));
        }

        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO gps_accuracy_policy (id, target_accuracy_meters, updated_by, updated_at)
                VALUES (1, :accuracy, :updatedBy, now())
                ON CONFLICT (id) DO UPDATE SET
                    target_accuracy_meters = EXCLUDED.target_accuracy_meters,
                    updated_by = EXCLUDED.updated_by,
                    updated_at = EXCLUDED.updated_at
                SQL,
            ['accuracy' => $accuracy, 'updatedBy' => $adminUserId],
        );

        return $this->get();
    }
}
