<?php

declare(strict_types=1);

namespace AnnounceLog\Announcement;

use Nene2\Database\DatabaseQueryExecutorInterface;

class AnnouncementRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    public function create(string $title, string $body, int $priority, string $startsAt, string $endsAt, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO announcements (title, body, priority, starts_at, ends_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$title, $body, $priority, $startsAt, $endsAt, $now, $now],
        );
    }

    public function update(int $id, string $title, string $body, int $priority, string $startsAt, string $endsAt, string $now): int
    {
        return $this->db->execute(
            'UPDATE announcements
             SET title = ?, body = ?, priority = ?, starts_at = ?, ends_at = ?, updated_at = ?
             WHERE id = ?',
            [$title, $body, $priority, $startsAt, $endsAt, $now, $id],
        );
    }

    public function delete(int $id): int
    {
        return $this->db->execute('DELETE FROM announcements WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM announcements WHERE id = ?', [$id]);
    }

    /**
     * Active announcements for the given instant, newest/highest priority first.
     * When $userId is provided, dismissed announcements are excluded.
     *
     * @return list<array<string, mixed>>
     */
    public function listActive(string $now, ?int $userId): array
    {
        if ($userId === null) {
            /** @var list<array<string, mixed>> */
            return $this->db->fetchAll(
                'SELECT * FROM announcements
                 WHERE starts_at <= ? AND ends_at > ?
                 ORDER BY priority DESC, id DESC',
                [$now, $now],
            );
        }

        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT a.* FROM announcements a
             WHERE a.starts_at <= ? AND a.ends_at > ?
               AND NOT EXISTS (
                   SELECT 1 FROM announcement_dismissals d
                   WHERE d.announcement_id = a.id AND d.user_id = ?
               )
             ORDER BY a.priority DESC, a.id DESC',
            [$now, $now, $userId],
        );
    }

    /** Idempotent: a repeated dismissal is a no-op. */
    public function dismiss(int $userId, int $announcementId, string $now): void
    {
        $this->db->execute(
            'INSERT INTO announcement_dismissals (user_id, announcement_id, dismissed_at)
             VALUES (?, ?, ?)
             ON CONFLICT(user_id, announcement_id) DO NOTHING',
            [$userId, $announcementId, $now],
        );
    }
}
