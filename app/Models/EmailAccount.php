<?php

declare(strict_types=1);

namespace App\Models;

final readonly class EmailAccount
{
    public function __construct(
        public int     $id,
        public string  $uuid,
        public int     $organizationId,
        public string  $name,
        public string  $host,
        public int     $port,
        public string  $protocol,
        public string  $encryption,
        public string  $username,
        public string  $password,
        public string  $mailFolder,
        public string  $defaultPriority,
        public bool    $autoCreateUser,
        public bool    $isActive,
        public ?string $lastFetchedAt,
        public ?string $lastError,
        public string  $createdAt,
        public string  $updatedAt,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id:              (int)  $row['id'],
            uuid:                   $row['uuid'],
            organizationId:  (int)  $row['organization_id'],
            name:                   $row['name'],
            host:                   $row['host'],
            port:            (int)  $row['port'],
            protocol:               $row['protocol'],
            encryption:             $row['encryption'],
            username:               $row['username'],
            password:               $row['password'],
            mailFolder:             $row['mail_folder'],
            defaultPriority:        $row['default_priority'],
            autoCreateUser:  (bool) $row['auto_create_user'],
            isActive:        (bool) $row['is_active'],
            lastFetchedAt:          $row['last_fetched_at'] ?? null,
            lastError:              $row['last_error']       ?? null,
            createdAt:              $row['created_at'],
            updatedAt:              $row['updated_at'],
        );
    }

    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'uuid'             => $this->uuid,
            'organization_id'  => $this->organizationId,
            'name'             => $this->name,
            'host'             => $this->host,
            'port'             => $this->port,
            'protocol'         => $this->protocol,
            'encryption'       => $this->encryption,
            'username'         => $this->username,
            'mail_folder'      => $this->mailFolder,
            'default_priority' => $this->defaultPriority,
            'auto_create_user' => $this->autoCreateUser,
            'is_active'        => $this->isActive,
            'last_fetched_at'  => $this->lastFetchedAt,
            'last_error'       => $this->lastError,
            'created_at'       => $this->createdAt,
            'updated_at'       => $this->updatedAt,
        ];
    }
}
