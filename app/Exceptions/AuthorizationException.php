<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class AuthorizationException extends RuntimeException
{
    public function __construct(string $message = 'Acesso negado.')
    {
        parent::__construct($message, 403);
    }
}
