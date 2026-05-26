<?php

declare(strict_types=1);

namespace Audit\Task;

final readonly class Task
{
    public function __construct(
        public int    $id,
        public string $title,
        public string $body,
        public string $status,
        public int    $actorId,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'title'      => $this->title,
            'body'       => $this->body,
            'status'     => $this->status,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
