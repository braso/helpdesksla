<?php

declare(strict_types=1);

namespace App\Models;

final readonly class TimeEntry
{
    public function __construct(
        public int     $id,
        public int     $ticketId,
        public int     $userId,
        public string  $startedAt,
        public ?string $stoppedAt,
        public ?int    $durationSeconds,
        public ?string $notes,
        public string  $createdAt,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id:              (int) $row['id'],
            ticketId:        (int) $row['ticket_id'],
            userId:          (int) $row['user_id'],
            startedAt:       $row['started_at'],
            stoppedAt:       $row['stopped_at']       ?? null,
            durationSeconds: isset($row['duration_seconds']) ? (int) $row['duration_seconds'] : null,
            notes:           $row['notes']             ?? null,
            createdAt:       $row['created_at'],
        );
    }

    public function isRunning(): bool
    {
        return $this->stoppedAt === null;
    }

    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'ticket_id'        => $this->ticketId,
            'user_id'          => $this->userId,
            'started_at'       => $this->startedAt,
            'stopped_at'       => $this->stoppedAt,
            'duration_seconds' => $this->durationSeconds,
            'is_running'       => $this->isRunning(),
            'notes'            => $this->notes,
            'created_at'       => $this->createdAt,
        ];
    }
}
