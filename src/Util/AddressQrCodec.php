<?php

namespace App\Util;

final class AddressQrCodec
{
    private const PREFIX = 'ADR:';

    public static function encode(int $addressId): string
    {
        return self::PREFIX . $addressId;
    }

    public static function decode(string $identifier): ?int
    {
        $value = trim($identifier);
        if (!str_starts_with($value, self::PREFIX)) {
            return null;
        }

        $id = substr($value, strlen(self::PREFIX));
        if ($id === '' || !ctype_digit($id)) {
            return null;
        }

        return (int) $id;
    }
}
