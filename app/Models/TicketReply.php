<?php

declare(strict_types=1);

namespace App\Models;

final readonly class TicketReply
{
    public function __construct(
        public int     $id,
        public string  $uuid,
        public int     $ticketId,
        public int     $authorId,
        public string  $body,
        public string  $type,       // 'reply' | 'note' | 'system'
        public bool    $isPrivate,
        public string  $source,
        public ?array  $metadata,
        public string  $createdAt,
        public string  $updatedAt,
        public ?string $authorName = null,
        public bool    $authorIsStaff = false,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id:        (int)  $row['id'],
            uuid:             $row['uuid'],
            ticketId:  (int)  $row['ticket_id'],
            authorId:  (int)  $row['author_id'],
            body:             $row['body'],
            type:             $row['type'],
            isPrivate: (bool) $row['is_private'],
            source:           $row['source'],
            metadata:  isset($row['metadata'])
                ? json_decode($row['metadata'], associative: true)
                : null,
            createdAt:        $row['created_at'],
            updatedAt:        $row['updated_at'],
            authorName:       $row['author_name'] ?? null,
            authorIsStaff:    (bool) ($row['author_is_staff'] ?? false),
        );
    }

    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'uuid'       => $this->uuid,
            'ticket_id'  => $this->ticketId,
            'author_id'  => $this->authorId,
            'body'       => $this->body,
            'type'       => $this->type,
            'is_private' => $this->isPrivate,
            'source'     => $this->source,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'format'          => ($this->metadata['format'] ?? 'text') === 'html' ? 'html' : 'text',
            'author_name'     => $this->authorName,
            'author_is_staff' => $this->authorIsStaff,
        ];
    }
}
