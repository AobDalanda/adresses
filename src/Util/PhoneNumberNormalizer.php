<?php

namespace App\Util;

final class PhoneNumberNormalizer
{
    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';
        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '00')) {
            return substr($digits, 2);
        }

        return $digits;
    }
}
