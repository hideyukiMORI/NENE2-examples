<?php

declare(strict_types=1);

namespace ReminderLog\Reminder;

use Nene2\Database\DatabaseQueryExecutorInterface;

class ReminderRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null owner-scoped */
    public function findOwned(int $id, int $userId): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, user_id, title, remind_at, status, created_at FROM reminders WHERE id = ? AND user_id = ?',
            [$id, $userId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function listOwned(int $userId, ?string $status, int $limit, int $offset): array
    {
        $where = ['user_id = ?'];
        $params = [$userId];
        if ($status !== null) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        $params[] = $limit;
        $params[] = $offset;

        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, user_id, title, remind_at, status, created_at FROM reminders WHERE '
            . implode(' AND ', $where) . ' ORDER BY remind_at ASC LIMIT ? OFFSET ?',
            $params,
        );
    }

    public function create(int $userId, string $title, string $remindAt, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO reminders (user_id, title, remind_at, status, created_at) VALUES (?, ?, ?, \'pending\', ?)',
            [$userId, $title, $remindAt, $now],
        );
    }

    /** Cancel only if currently pending. */
    public function cancel(int $id, int $userId): bool
    {
        return $this->db->execute(
            "UPDATE reminders SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'pending'",
            [$id, $userId],
        ) > 0;
    }
}
