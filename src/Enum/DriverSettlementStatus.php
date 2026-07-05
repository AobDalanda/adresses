<?php

declare(strict_types=1);

namespace App\Enum;

enum DriverSettlementStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';

    public static function fromDatabase(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }

    public function toDatabase(): string
    {
        return strtoupper($this->value);
    }
}
