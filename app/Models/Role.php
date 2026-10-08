<?php

declare(strict_types=1);

namespace App\Models;

final readonly class Role
{
    public function __construct(
        public int    $id,
        public string $name,
        public string $slug,
        public bool   $isSystem,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id:       (int)  $row['id'],
            name:            $row['name'],
            slug:            $row['slug'],
            isSystem: (bool) $row['is_system'],
        );
    }
}
