<?php

declare(strict_types=1);

namespace ReservationLog\Reservation;

final readonly class Booking
{
    public function __construct(
        public int $id,
        public int $resourceId,
        public int $userId,
        public string $startsAt,
        public string $endsAt,
        public ?string $note,
        public string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['resource_id'],
            (int) $row['user_id'],
            (string) $row['starts_at'],
            (string) $row['ends_at'],
            $row['note'] !== null ? (string) $row['note'] : null,
            (string) $row['created_at'],
        );
    }

    /**
     * Public view — excludes user_id (IDOR prevention).
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'resource_id' => $this->resourceId,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'note' => $this->note,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * Admin view — includes user_id for auditing.
     *
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return ['user_id' => $this->userId] + $this->toPublicArray();
    }
}
