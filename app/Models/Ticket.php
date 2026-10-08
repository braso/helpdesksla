<?php

declare(strict_types=1);

namespace App\Models;

final readonly class Ticket
{
    public function __construct(
        public int     $id,
        public string  $uuid,
        public string  $ticketNumber,
        public string  $subject,
        public string  $description,
        public int     $requesterId,
        public ?int    $assignedAgentId,
        public ?int    $teamId,
        public ?int    $organizationId,
        public ?int    $categoryId,
        public ?int    $slaPolicyId,
        public string  $status,
        public string  $priority,
        public string  $source,
        public string  $type,
        public ?string $slaFrtDueAt,
        public ?string $slaRtDueAt,
        public ?string $firstResponseAt,
        public ?string $resolvedAt,
        public ?string $closedAt,
        public ?int    $rating,
        public ?string $ratingComment,
        public ?string $ratedAt,
        public string  $createdAt,
        public string  $updatedAt,
        public bool    $slaFrtBreached = false,
        public bool    $slaRtBreached = false,
        public ?string $requesterName = null,
        public ?string $agentName = null,
        public ?string $organizationName = null,
        public ?string $lastReplyPreview = null,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id:              (int) $row['id'],
            uuid:            $row['uuid'],
            ticketNumber:    $row['ticket_number'],
            subject:         $row['subject'],
            description:     $row['description'],
            requesterId:     (int) $row['requester_id'],
            assignedAgentId: isset($row['assigned_agent_id']) ? (int) $row['assigned_agent_id'] : null,
            teamId:          isset($row['team_id'])           ? (int) $row['team_id']           : null,
            organizationId:  isset($row['organization_id'])   ? (int) $row['organization_id']   : null,
            categoryId:      isset($row['category_id'])       ? (int) $row['category_id']       : null,
            slaPolicyId:     isset($row['sla_policy_id'])     ? (int) $row['sla_policy_id']     : null,
            status:          $row['status'],
            priority:        $row['priority'],
            source:          $row['source'],
            type:            $row['type'],
            slaFrtDueAt:     $row['sla_frt_due_at']    ?? null,
            slaRtDueAt:      $row['sla_rt_due_at']     ?? null,
            firstResponseAt: $row['first_response_at'] ?? null,
            resolvedAt:      $row['resolved_at']       ?? null,
            closedAt:        $row['closed_at']         ?? null,
            rating:          isset($row['rating'])         ? (int) $row['rating']     : null,
            ratingComment:   $row['rating_comment']        ?? null,
            ratedAt:         $row['rated_at']              ?? null,
            createdAt:       $row['created_at'],
            updatedAt:       $row['updated_at'],
            slaFrtBreached:  (bool) ($row['sla_frt_breached'] ?? false),
            slaRtBreached:   (bool) ($row['sla_rt_breached']  ?? false),
            requesterName:   $row['requester_name']     ?? null,
            agentName:       $row['agent_name']         ?? null,
            organizationName: $row['organization_name'] ?? null,
            lastReplyPreview: $row['last_reply_preview'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'id'                => $this->id,
            'uuid'              => $this->uuid,
            'ticket_number'     => $this->ticketNumber,
            'subject'           => $this->subject,
            'description'       => $this->description,
            'requester_id'      => $this->requesterId,
            'assigned_agent_id' => $this->assignedAgentId,
            'team_id'           => $this->teamId,
            'organization_id'   => $this->organizationId,
            'category_id'       => $this->categoryId,
            'sla_policy_id'     => $this->slaPolicyId,
            'status'            => $this->status,
            'priority'          => $this->priority,
            'source'            => $this->source,
            'type'              => $this->type,
            'sla_frt_due_at'    => $this->slaFrtDueAt,
            'sla_rt_due_at'     => $this->slaRtDueAt,
            'first_response_at' => $this->firstResponseAt,
            'resolved_at'       => $this->resolvedAt,
            'closed_at'         => $this->closedAt,
            'rating'            => $this->rating,
            'rating_comment'    => $this->ratingComment,
            'rated_at'          => $this->ratedAt,
            'created_at'        => $this->createdAt,
            'updated_at'        => $this->updatedAt,
            'sla_frt_breached'  => $this->slaFrtBreached,
            'sla_rt_breached'   => $this->slaRtBreached,
            'requester_name'    => $this->requesterName,
            'agent_name'        => $this->agentName,
            'organization_name' => $this->organizationName,
            'last_reply_preview' => $this->lastReplyPreview,
        ];
    }
}
