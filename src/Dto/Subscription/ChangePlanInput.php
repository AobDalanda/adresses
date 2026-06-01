<?php

namespace App\Dto\Subscription;

use Symfony\Component\Validator\Constraints as Assert;

final class ChangePlanInput
{
    #[Assert\NotBlank]
    public ?string $planCode = null;

    #[Assert\NotBlank]
    public ?string $paymentProvider = null;
}
