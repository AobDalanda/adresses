<?php

declare(strict_types=1);

namespace App\Enum;

enum ProviderAuthorizationStatus: string
{
    case INACTIVE = 'INACTIVE';
    case ACTIVE = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';
}
