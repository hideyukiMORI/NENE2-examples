<?php

declare(strict_types=1);

namespace TicketLog\Ticket;

use Nene2\Database\DatabaseConstraintException;
use Nene2\Database\DatabaseQueryExecutorInterface;

class TicketRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findEvent(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne('SELECT id, name, capacity, created_at FROM events WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> events with a computed `sold` count */
    public function listEvents(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT e.id, e.name, e.capacity, e.created_at,
                    (SELECT COUNT(*) FROM tickets t WHERE t.event_id = e.id) AS sold
             FROM events e ORDER BY e.id ASC',
        );
    }

    public function soldCount(int $eventId): int
    {
        $row = $this->db->fetchOne('SELECT COUNT(*) AS c FROM tickets WHERE event_id = ?', [$eventId]);
        return $row === null ? 0 : (int) $row['c'];
    }

    public function hasTicket(int $eventId, int $userId): bool
    {
        return $this->db->fetchOne(
            'SELECT 1 AS x FROM tickets WHERE event_id = ? AND user_id = ?',
            [$eventId, $userId],
        ) !== null;
    }

    public function createEvent(string $name, int $capacity, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO events (name, capacity, created_at) VALUES (?, ?, ?)',
            [$name, $capacity, $now],
        );
    }

    /**
     * Purchase a ticket. The capacity guard is a single conditional
     * INSERT…SELECT so it cannot oversell under concurrency; the
     * UNIQUE(event_id, user_id) constraint catches a concurrent double-buy.
     *
     * @return 'not_found'|'duplicate'|'sold_out'|'purchased'
     */
    public function purchase(int $eventId, int $userId, string $now): string
    {
        if ($this->findEvent($eventId) === null) {
            return 'not_found';
        }
        if ($this->hasTicket($eventId, $userId)) {
            return 'duplicate';
        }

        try {
            $affected = $this->db->execute(
                'INSERT INTO tickets (event_id, user_id, created_at)
                 SELECT ?, ?, ?
                 WHERE (SELECT COUNT(*) FROM tickets WHERE event_id = ?) < (SELECT capacity FROM events WHERE id = ?)',
                [$eventId, $userId, $now, $eventId, $eventId],
            );
        } catch (DatabaseConstraintException) {
            return 'duplicate'; // concurrent double-buy hit the UNIQUE constraint
        }

        return $affected === 0 ? 'sold_out' : 'purchased';
    }

    /**
     * @return 'not_found'|'not_owner'|'cancelled'
     */
    public function cancel(int $ticketId, int $userId): string
    {
        $ticket = $this->db->fetchOne('SELECT id, user_id FROM tickets WHERE id = ?', [$ticketId]);
        if ($ticket === null) {
            return 'not_found';
        }
        if ((int) $ticket['user_id'] !== $userId) {
            return 'not_owner';
        }
        $this->db->execute('DELETE FROM tickets WHERE id = ? AND user_id = ?', [$ticketId, $userId]);
        return 'cancelled';
    }

    public function findUserTicket(int $eventId, int $userId): ?int
    {
        $row = $this->db->fetchOne(
            'SELECT id FROM tickets WHERE event_id = ? AND user_id = ?',
            [$eventId, $userId],
        );
        return $row === null ? null : (int) $row['id'];
    }
}
