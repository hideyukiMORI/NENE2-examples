<?php

declare(strict_types=1);

namespace WatchLog\Watch;

use Nene2\Database\DatabaseQueryExecutorInterface;

class WatchRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, title, media_type, status, rating, note, created_at, updated_at, archived_at
             FROM watch_entries WHERE id = ?',
            [$id],
        );
    }

    /** @return list<array<string, mixed>> */
    public function list(?WatchStatus $status, ?MediaType $type, bool $includeArchived, int $limit, int $offset): array
    {
        $conds = [];
        $params = [];
        if (!$includeArchived) {
            $conds[] = 'archived_at IS NULL';
        }
        if ($status !== null) {
            $conds[] = 'status = ?';
            $params[] = $status->value;
        }
        if ($type !== null) {
            $conds[] = 'media_type = ?';
            $params[] = $type->value;
        }
        $where = $conds === [] ? '' : ' WHERE ' . implode(' AND ', $conds);
        $params[] = $limit;
        $params[] = $offset;

        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, title, media_type, status, rating, note, created_at, updated_at, archived_at
             FROM watch_entries' . $where . ' ORDER BY id DESC LIMIT ? OFFSET ?',
            $params,
        );
    }

    public function create(string $title, MediaType $type, WatchStatus $status, ?int $rating, string $note, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO watch_entries (title, media_type, status, rating, note, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$title, $type->value, $status->value, $rating, $note, $now, $now],
        );
    }

    /**
     * Update status, and (when provided) rating / note. A provided null rating
     * clears it.
     *
     * @param array<string, mixed> $existing
     */
    public function updateStatus(int $id, array $existing, WatchStatus $status, bool $ratingProvided, ?int $rating, ?string $note, string $now): void
    {
        $newRating = $ratingProvided ? $rating : ($existing['rating'] === null ? null : (int) $existing['rating']);
        $newNote = $note ?? (string) $existing['note'];
        $this->db->execute(
            'UPDATE watch_entries SET status = ?, rating = ?, note = ?, updated_at = ? WHERE id = ?',
            [$status->value, $newRating, $newNote, $now, $id],
        );
    }

    public function archive(int $id, string $now): void
    {
        $this->db->execute('UPDATE watch_entries SET archived_at = ?, updated_at = ? WHERE id = ?', [$now, $now, $id]);
    }

    public function restore(int $id, string $now): void
    {
        $this->db->execute('UPDATE watch_entries SET archived_at = NULL, updated_at = ? WHERE id = ?', [$now, $id]);
    }

    public function delete(int $id): bool
    {
        return $this->db->execute('DELETE FROM watch_entries WHERE id = ?', [$id]) > 0;
    }
}
