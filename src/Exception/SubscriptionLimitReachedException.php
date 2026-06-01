<?php

namespace App\Exception;

final class SubscriptionLimitReachedException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $requiredPlan,
        private readonly string $errorCode = 'SUBSCRIPTION_LIMIT_REACHED'
    ) {
        parent::__construct($message);
    }

    public function getRequiredPlan(): string
    {
        return $this->requiredPlan;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
