<?php

namespace App\Service;

use Symfony\Component\Uid\Uuid;

final class QrTokenGenerator
{
    public function generate(): string
    {
        return 'ADR_'.strtoupper(str_replace('-', '', Uuid::v7()->toRfc4122()));
    }
}
