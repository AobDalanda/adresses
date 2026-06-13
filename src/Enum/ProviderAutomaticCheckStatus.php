<?php

declare(strict_types=1);

namespace App\Enum;

enum ProviderAutomaticCheckStatus: string
{
    case PENDING = 'PENDING';
    case RUNNING = 'RUNNING';
    case PASSED = 'PASSED';
    case WARNING = 'WARNING';
    case FAILED = 'FAILED';
    case ERROR = 'ERROR';
}
