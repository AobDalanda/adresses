<?php

declare(strict_types=1);

namespace App\Enum;

enum ProviderActivity: string
{
    case DELIVERY = 'DELIVERY';
    case PEOPLE_TRANSPORT = 'PEOPLE_TRANSPORT';
}
