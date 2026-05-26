<?php

declare(strict_types=1);

namespace Version\Shared;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class NoteRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {
    }

    /** @param list<string> $tags */
    public function create(string $title, string $body, array $tags = []): Note
    {
        $now     = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $tagsJson = json_encode($tags, JSON_THROW_ON_ERROR);

        $this->executor->execute(
            'INSERT INTO notes (title, body, tags, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$title, $body, $tagsJson, $now, $now],
        );

        return $this->findById((int) $this->executor->lastInsertId())
            ?? throw new \RuntimeException('Failed to create note.');
    }

    /** @return list<Note> */
    public function findAll(int $limit = 50, int $offset = 0): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM notes ORDER BY id DESC LIMIT ? OFFSET ?',
            [$limit, $offset],
        );

        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }

    public function findById(int $id): ?Note
    {
        $rows = $this->executor->fetchAll('SELECT * FROM notes WHERE id = ?', [$id]);

        return $rows !== [] ? $this->hydrate($rows[0]) : null;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Note
    {
        /** @var list<string> $tags */
        $tags = json_decode((string) $row['tags'], true, 512, JSON_THROW_ON_ERROR);

        return new Note(
            (int) $row['id'],
            (string) $row['title'],
            (string) $row['body'],
            $tags,
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
