<?php

namespace App\Dto\Subscription;

use Symfony\Component\Validator\Constraints as Assert;

final class ManualPaymentWebhookInput
{
    #[Assert\NotBlank]
    public ?string $reference = null;

    #[Assert\NotBlank]
    public ?string $status = null;

    #[Assert\NotNull]
    public ?int $amount = null;

    #[Assert\NotBlank]
    public ?string $currency = null;
}
