<?php

declare(strict_types=1);

namespace TimeLog\Timer;

final readonly class TimeEntry
{
    public function __construct(
        public int $id,
        public string $label,
        public string $startTime,
        public ?string $endTime,
        public string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['label'],
            (string) $row['start_time'],
            $row['end_time'] !== null ? (string) $row['end_time'] : null,
            (string) $row['created_at'],
        );
    }

    /** The presence/absence of end_time encodes the running state — no status column. */
    public function isRunning(): bool
    {
        return $this->endTime === null;
    }

    public function durationSeconds(): ?int
    {
        if ($this->endTime === null) {
            return null; // still running — no duration yet
        }
        $start = new \DateTimeImmutable($this->startTime);
        $end = new \DateTimeImmutable($this->endTime);
        return $end->getTimestamp() - $start->getTimestamp();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'running' => $this->isRunning(),
            'duration_seconds' => $this->durationSeconds(),
        ];
    }
}
