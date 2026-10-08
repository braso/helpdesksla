<?php

declare(strict_types=1);

namespace App\Models;

final readonly class Permission
{
    public function __construct(
        public int    $id,
        public string $module,
        public string $action,
        public string $slug,        // formato: 'module.action'  ex: 'tickets.edit'
        public ?string $description,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id:          (int) $row['id'],
            module:            $row['module'],
            action:            $row['action'],
            slug:              $row['slug'],
            description:       $row['description'] ?? null,
        );
    }
}
