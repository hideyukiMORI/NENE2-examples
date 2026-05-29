<?php

declare(strict_types=1);

namespace DeadLetterLog\Queue;

final readonly class Message
{
    public function __construct(
        public int $id,
        public string $queue,
        public string $payload,
        public MessageStatus $status,
        public int $retryCount,
        public int $maxRetries,
        public ?string $retryAfter,
        public ?string $lastError,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['queue'],
            (string) $row['payload'],
            MessageStatus::from((string) $row['status']),
            (int) $row['retry_count'],
            (int) $row['max_retries'],
            $row['retry_after'] !== null ? (string) $row['retry_after'] : null,
            $row['last_error'] !== null ? (string) $row['last_error'] : null,
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'queue' => $this->queue,
            'payload' => $this->payload,
            'status' => $this->status->value,
            'retry_count' => $this->retryCount,
            'max_retries' => $this->maxRetries,
            'retry_after' => $this->retryAfter,
            'last_error' => $this->lastError,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
