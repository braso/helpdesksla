<?php

declare(strict_types=1);

namespace App\Models;

final readonly class User
{
    public function __construct(
        public int     $id,
        public string  $uuid,
        public string  $name,
        public string  $firstName,
        public string  $lastName,
        public string  $email,
        public string  $password,
        public bool    $isActive,
        public string  $timezone,
        public string  $locale,
        public ?string $phone,
        public ?string $phoneMobile,
        public ?string $position,
        public ?string $department,
        public ?string $emailVerifiedAt,
        public ?string $lastLoginAt,
        public string  $createdAt,
        public string  $updatedAt,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id:              (int)  $row['id'],
            uuid:                   $row['uuid'],
            name:                   $row['name'],
            firstName:              $row['first_name']    ?? '',
            lastName:               $row['last_name']     ?? '',
            email:                  $row['email'],
            password:               $row['password'],
            isActive:        (bool) $row['is_active'],
            timezone:               $row['timezone']          ?? 'UTC',
            locale:                 $row['locale']            ?? 'pt_BR',
            phone:                  $row['phone']             ?? null,
            phoneMobile:            $row['phone_mobile']      ?? null,
            position:               $row['position']          ?? null,
            department:             $row['department']        ?? null,
            emailVerifiedAt:        $row['email_verified_at'] ?? null,
            lastLoginAt:            $row['last_login_at']     ?? null,
            createdAt:              $row['created_at'],
            updatedAt:              $row['updated_at'],
        );
    }

    public function toPublicArray(): array
    {
        return [
            'id'           => $this->id,
            'uuid'         => $this->uuid,
            'name'         => $this->name,
            'first_name'   => $this->firstName,
            'last_name'    => $this->lastName,
            'email'        => $this->email,
            'phone'        => $this->phone,
            'phone_mobile' => $this->phoneMobile,
            'position'     => $this->position,
            'department'   => $this->department,
            'timezone'     => $this->timezone,
            'locale'       => $this->locale,
            'is_active'    => $this->isActive,
        ];
    }
}
