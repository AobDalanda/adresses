<?php

declare(strict_types=1);

namespace App\Security\Exception;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

final class SessionInvalidatedException extends AuthenticationException
{
    public function __construct()
    {
        parent::__construct('SESSION_INVALIDATED');
    }

    public function getMessageKey(): string
    {
        return 'SESSION_INVALIDATED';
    }
}
