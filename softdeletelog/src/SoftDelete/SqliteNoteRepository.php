<?php

declare(strict_types=1);

namespace SoftDelete;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class SqliteNoteRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {
    }

    public function create(string $title, string $body, string $now): Note
    {
        $id = $this->executor->insert(
            'INSERT INTO notes (title, body, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$title, $body, $now, $now],
        );

        return new Note($id, $title, $body, $now, $now, null);
    }

    public function findById(int $id, bool $includeTrashed = false): ?Note
    {
        $sql  = $includeTrashed
            ? 'SELECT * FROM notes WHERE id = ?'
            : 'SELECT * FROM notes WHERE id = ? AND deleted_at IS NULL';
        $rows = $this->executor->fetchAll($sql, [$id]);

        if ($rows === []) {
            return null;
        }

        return $this->hydrate($rows[0]);
    }

    /** @return list<Note> */
    public function listActive(): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM notes WHERE deleted_at IS NULL ORDER BY created_at DESC',
            [],
        );

        return array_map($this->hydrate(...), $rows);
    }

    /** @return list<Note> */
    public function listTrashed(): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM notes WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC',
            [],
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function softDelete(int $id, string $now): ?Note
    {
        $note = $this->findById($id);
        if ($note === null) {
            return null;
        }

        $this->executor->execute(
            'UPDATE notes SET deleted_at = ? WHERE id = ?',
            [$now, $id],
        );

        return new Note($note->id, $note->title, $note->body, $note->createdAt, $note->updatedAt, $now);
    }

    public function restore(int $id): ?Note
    {
        $note = $this->findById($id, includeTrashed: true);
        if ($note === null || !$note->isDeleted()) {
            return null;
        }

        $this->executor->execute(
            'UPDATE notes SET deleted_at = NULL WHERE id = ?',
            [$id],
        );

        return new Note($note->id, $note->title, $note->body, $note->createdAt, $note->updatedAt, null);
    }

    public function purge(int $id): bool
    {
        $note = $this->findById($id, includeTrashed: true);
        if ($note === null || !$note->isDeleted()) {
            return false;
        }

        $this->executor->execute('DELETE FROM notes WHERE id = ?', [$id]);

        return true;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Note
    {
        return new Note(
            (int) $row['id'],
            (string) $row['title'],
            (string) $row['body'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
            isset($row['deleted_at']) ? (string) $row['deleted_at'] : null,
        );
    }
}
