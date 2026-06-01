<?php

namespace App\Dto\Subscription;

use Symfony\Component\Validator\Constraints as Assert;

final class CheckoutSubscriptionInput
{
    #[Assert\NotBlank]
    public ?string $planCode = null;

    #[Assert\NotBlank]
    public ?string $paymentProvider = null;

    #[Assert\Length(max: 20)]
    public ?string $phoneNumber = null;
}
