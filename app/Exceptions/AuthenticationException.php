<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class AuthenticationException extends RuntimeException
{
    public function __construct(string $message = 'Não autenticado.')
    {
        parent::__construct($message, 401);
    }
}
