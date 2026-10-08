<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class NotFoundException extends RuntimeException
{
    /** Recebe o nome do recurso ("User") ou uma frase pronta terminada em ponto ("Empresa não encontrada."). */
    public function __construct(string $resource = 'Resource')
    {
        parent::__construct(str_ends_with($resource, '.') ? $resource : "{$resource} not found.", 404);
    }
}
