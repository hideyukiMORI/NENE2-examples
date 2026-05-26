<?php

declare(strict_types=1);

namespace Notification\Notification;

final readonly class Notification
{
    public function __construct(
        public int $id,
        public int $userId,
        public string $title,
        public string $body,
        public ?string $readAt,
        public string $createdAt,
    ) {}

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'user_id'    => $this->userId,
            'title'      => $this->title,
            'body'       => $this->body,
            'read'       => $this->isRead(),
            'read_at'    => $this->readAt,
            'created_at' => $this->createdAt,
        ];
    }
}
