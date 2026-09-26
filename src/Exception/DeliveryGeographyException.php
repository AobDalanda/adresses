<?php

declare(strict_types=1);

namespace App\Exception;

final class DeliveryGeographyException extends \DomainException
{
    /** @param list<string> $allowedCountryCodes */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly array $allowedCountryCodes = [],
    ) {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** @return list<string> */
    public function getAllowedCountryCodes(): array
    {
        return $this->allowedCountryCodes;
    }
}
