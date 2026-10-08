<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Validador fluente e imutável por campo.
 * Acumula erros em vez de lançar na primeira falha,
 * para que o cliente receba todos os problemas de uma só vez.
 */
final class Validator
{
    /** @var array<string, string[]> */
    private array $errors = [];

    public function required(string $field, mixed $value): static
    {
        if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
            $this->errors[$field][] = "O campo {$field} é obrigatório.";
        }

        return $this;
    }

    public function maxLength(string $field, string $value, int $max): static
    {
        if (mb_strlen($value, 'UTF-8') > $max) {
            $this->errors[$field][] = "O campo {$field} não pode exceder {$max} caracteres.";
        }

        return $this;
    }

    /** @param string[] $allowed */
    public function enum(string $field, mixed $value, array $allowed): static
    {
        if ($value !== null && !in_array($value, $allowed, strict: true)) {
            $this->errors[$field][] = sprintf(
                'O campo %s deve ser um dos valores: %s.',
                $field,
                implode(', ', $allowed)
            );
        }

        return $this;
    }

    public function positiveInteger(string $field, mixed $value): static
    {
        if ($value !== null && (!is_numeric($value) || (int) $value <= 0)) {
            $this->errors[$field][] = "O campo {$field} deve ser um número inteiro positivo.";
        }

        return $this;
    }

    public function passes(): bool
    {
        return empty($this->errors);
    }

    /** @return array<string, string[]> */
    public function errors(): array
    {
        return $this->errors;
    }
}
