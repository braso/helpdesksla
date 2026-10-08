<?php

declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

/** Falha ao usar a IA, com mensagem pronta para mostrar ao usuário. */
final class AiException extends RuntimeException {}
