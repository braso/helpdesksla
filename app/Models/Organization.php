<?php

declare(strict_types=1);

namespace App\Models;

final readonly class Organization
{
    public function __construct(
        public int     $id,
        public string  $uuid,
        public string  $name,
        public ?string $tradeName,
        public ?string $cnpj,
        public ?string $ie,
        public ?string $im,
        public ?string $phone,
        public ?string $email,
        public ?string $website,
        public ?string $domain,
        public bool    $isActive,
        public ?int    $slaPolicyId,
        public ?string $notes,
        public ?string $addressZip,
        public ?string $addressStreet,
        public ?string $addressNumber,
        public ?string $addressComplement,
        public ?string $addressNeighborhood,
        public ?string $addressCity,
        public ?string $addressState,
        public ?string $addressCountry,
        public string  $createdAt,
        public string  $updatedAt,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id:                  (int)  $row['id'],
            uuid:                        $row['uuid'],
            name:                        $row['name'],
            tradeName:                   $row['trade_name']           ?? null,
            cnpj:                        $row['cnpj']                 ?? null,
            ie:                          $row['ie']                   ?? null,
            im:                          $row['im']                   ?? null,
            phone:                       $row['phone']                ?? null,
            email:                       $row['email']                ?? null,
            website:                     $row['website']              ?? null,
            domain:                      $row['domain']               ?? null,
            isActive:            (bool)  $row['is_active'],
            slaPolicyId:         isset($row['sla_policy_id']) ? (int) $row['sla_policy_id'] : null,
            notes:                       $row['notes']                ?? null,
            addressZip:                  $row['address_zip']          ?? null,
            addressStreet:               $row['address_street']       ?? null,
            addressNumber:               $row['address_number']       ?? null,
            addressComplement:           $row['address_complement']   ?? null,
            addressNeighborhood:         $row['address_neighborhood'] ?? null,
            addressCity:                 $row['address_city']         ?? null,
            addressState:                $row['address_state']        ?? null,
            addressCountry:              $row['address_country']      ?? null,
            createdAt:                   $row['created_at'],
            updatedAt:                   $row['updated_at'],
        );
    }

    public function toArray(): array
    {
        return [
            'id'                   => $this->id,
            'uuid'                 => $this->uuid,
            'name'                 => $this->name,
            'trade_name'           => $this->tradeName,
            'cnpj'                 => $this->cnpj,
            'ie'                   => $this->ie,
            'im'                   => $this->im,
            'phone'                => $this->phone,
            'email'                => $this->email,
            'website'              => $this->website,
            'domain'               => $this->domain,
            'is_active'            => $this->isActive,
            'sla_policy_id'        => $this->slaPolicyId,
            'notes'                => $this->notes,
            'address_zip'          => $this->addressZip,
            'address_street'       => $this->addressStreet,
            'address_number'       => $this->addressNumber,
            'address_complement'   => $this->addressComplement,
            'address_neighborhood' => $this->addressNeighborhood,
            'address_city'         => $this->addressCity,
            'address_state'        => $this->addressState,
            'address_country'      => $this->addressCountry,
            'created_at'           => $this->createdAt,
            'updated_at'           => $this->updatedAt,
        ];
    }
}
