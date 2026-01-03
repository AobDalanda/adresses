<?php

namespace App\Service;

use OpenLocationCode\CodeArea;
use OpenLocationCode\OpenLocationCode;

final class PlusCodeService
{
    public function encode(float $latitude, float $longitude, int $codeLength = 10): string
    {
        return OpenLocationCode::encode($latitude, $longitude, $codeLength);
    }

    public function decode(string $code): CodeArea
    {
        return OpenLocationCode::decode($code);
    }

    public function shorten(string $fullCode, float $latitude, float $longitude): string
    {
        return OpenLocationCode::shorten($fullCode, $latitude, $longitude);
    }

    public function recoverNearest(string $shortCode, float $latitude, float $longitude): string
    {
        return OpenLocationCode::recoverNearest($shortCode, $latitude, $longitude);
    }

    public function isValid(string $code): bool
    {
        return OpenLocationCode::isValid($code);
    }

    public function isShort(string $code): bool
    {
        return OpenLocationCode::isShort($code);
    }

    public function isFull(string $code): bool
    {
        return OpenLocationCode::isFull($code);
    }

    public function computeLatitudePrecision(int $codeLength): float
    {
        return OpenLocationCode::computeLatitudePrecision($codeLength);
    }
}
