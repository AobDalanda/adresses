<?php

declare(strict_types=1);

namespace App\Enum;

enum ProviderApplicationStatus: string
{
    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case AUTO_CHECK = 'AUTO_CHECK';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case CORRECTION_REQUIRED = 'CORRECTION_REQUIRED';
    case RESUBMITTED = 'RESUBMITTED';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
}
