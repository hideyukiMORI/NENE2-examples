<?php

declare(strict_types=1);

namespace ReservationLog\Reservation;

use Nene2\Database\DatabaseQueryExecutorInterface;

class ReservationRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    public function createResource(string $name, string $now): int
    {
        return $this->db->insert('INSERT INTO resources (name, created_at) VALUES (?, ?)', [$name, $now]);
    }

    public function resourceExists(int $id): bool
    {
        return $this->db->fetchOne('SELECT id FROM resources WHERE id = ?', [$id]) !== null;
    }

    /**
     * Book a slot if it does not overlap an existing one. Half-open intervals:
     * [start, end). Overlap iff existing.start < new.end AND existing.end > new.start.
     * Adjacent slots (end == start) do not overlap.
     */
    public function book(int $resourceId, int $userId, string $startsAt, string $endsAt, ?string $note, string $now): ?Booking
    {
        $overlap = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM bookings
             WHERE resource_id = ? AND starts_at < ? AND ends_at > ?',
            [$resourceId, $endsAt, $startsAt],
        );
        if ($overlap !== null && (int) $overlap['c'] > 0) {
            return null; // → 409
        }
        $id = $this->db->insert(
            'INSERT INTO bookings (resource_id, user_id, starts_at, ends_at, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$resourceId, $userId, $startsAt, $endsAt, $note, $now],
        );
        return Booking::fromRow((array) $this->db->fetchOne('SELECT * FROM bookings WHERE id = ?', [$id]));
    }

    /** @return list<Booking> all bookings for a resource (admin) */
    public function listByResource(int $resourceId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM bookings WHERE resource_id = ? ORDER BY starts_at ASC, id ASC',
            [$resourceId],
        );
        return array_map(Booking::fromRow(...), $rows);
    }

    /** @return list<Booking> a user's own bookings */
    public function listByUser(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM bookings WHERE user_id = ? ORDER BY starts_at ASC, id ASC',
            [$userId],
        );
        return array_map(Booking::fromRow(...), $rows);
    }

    public function find(int $id): ?Booking
    {
        $row = $this->db->fetchOne('SELECT * FROM bookings WHERE id = ?', [$id]);
        return $row === null ? null : Booking::fromRow($row);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM bookings WHERE id = ?', [$id]);
    }
}
