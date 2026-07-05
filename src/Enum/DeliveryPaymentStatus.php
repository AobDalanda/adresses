<?php

declare(strict_types=1);

namespace App\Enum;

enum DeliveryPaymentStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

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
