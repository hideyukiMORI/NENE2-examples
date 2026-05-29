<?php

declare(strict_types=1);

namespace WaitlistLog\Waitlist;

use Nene2\Database\DatabaseConstraintException;
use Nene2\Database\DatabaseQueryExecutorInterface;

class WaitlistRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null null if the user is already on the list (UNIQUE) */
    public function join(int $userId, ?string $note, string $now): ?array
    {
        try {
            $id = $this->db->insert(
                'INSERT INTO waitlist_entries (user_id, status, note, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?)',
                [$userId, WaitlistStatus::Waiting->value, $note, $now, $now],
            );
        } catch (DatabaseConstraintException) {
            return null; // UNIQUE(user_id) — already joined
        }
        return $this->find($id);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM waitlist_entries WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByUser(int $userId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM waitlist_entries WHERE user_id = ?', [$userId]);
    }

    /**
     * 1-based rank within the waiting queue. Approved/declined entries are not
     * counted, so the number reflects a real place in line.
     */
    public function positionOf(int $id): ?int
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM waitlist_entries
             WHERE status = 'waiting' AND id <= ?
               AND EXISTS (SELECT 1 FROM waitlist_entries w WHERE w.id = ? AND w.status = 'waiting')",
            [$id, $id],
        );
        $count = $row !== null ? (int) $row['c'] : 0;
        return $count > 0 ? $count : null; // null when the entry is not waiting
    }

    /** @return list<array<string, mixed>> */
    public function listAll(int $limit, int $offset): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT * FROM waitlist_entries ORDER BY id ASC LIMIT ? OFFSET ?',
            [$limit, $offset],
        );
    }

    /** @return 'ok'|'not_found'|'already_terminal' */
    public function transition(int $id, WaitlistStatus $newStatus, string $now): string
    {
        $entry = $this->find($id);
        if ($entry === null) {
            return 'not_found';
        }
        if (WaitlistStatus::from((string) $entry['status'])->isTerminal()) {
            return 'already_terminal';
        }
        $this->db->execute(
            'UPDATE waitlist_entries SET status = ?, updated_at = ? WHERE id = ?',
            [$newStatus->value, $now, $id],
        );
        return 'ok';
    }

    /** @return 'removed'|'not_found'|'not_waiting' */
    public function leave(int $userId): string
    {
        $entry = $this->findByUser($userId);
        if ($entry === null) {
            return 'not_found';
        }
        if (WaitlistStatus::from((string) $entry['status'])->isTerminal()) {
            return 'not_waiting'; // decision recorded — cannot leave
        }
        $this->db->execute('DELETE FROM waitlist_entries WHERE user_id = ?', [$userId]);
        return 'removed';
    }
}
