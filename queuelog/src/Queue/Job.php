<?php

declare(strict_types=1);

namespace Queue;

final readonly class Job
{
    public function __construct(
        public int         $id,
        public string      $type,
        public string      $payload,
        public JobPriority $priority,
        public JobStatus   $status,
        public int         $retryCount,
        public int         $maxRetries,
        public ?string     $idempotencyKey,
        public ?string     $claimedAt,
        public ?string     $workerId,
        public ?string     $error,
        public string      $createdAt,
        public string      $updatedAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'type'            => $this->type,
            'payload'         => json_decode($this->payload, true, 512, JSON_THROW_ON_ERROR),
            'priority'        => $this->priority->label(),
            'status'          => $this->status->value,
            'retry_count'     => $this->retryCount,
            'max_retries'     => $this->maxRetries,
            'idempotency_key' => $this->idempotencyKey,
            'claimed_at'      => $this->claimedAt,
            'worker_id'       => $this->workerId,
            'error'           => $this->error,
            'created_at'      => $this->createdAt,
            'updated_at'      => $this->updatedAt,
        ];
    }
}
