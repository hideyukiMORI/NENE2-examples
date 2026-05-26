<?php

declare(strict_types=1);

namespace Throttle\Notes;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class NoteRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $executor) {}

    public function create(string $content): Note
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
        $id  = $this->executor->insert(
            'INSERT INTO notes (content, created_at) VALUES (?, ?)',
            [$content, $now],
        );

        return new Note(id: $id, content: $content, createdAt: $now);
    }

    /** @return list<Note> */
    public function findAll(): array
    {
        /** @var list<array{id: int, content: string, created_at: string}> $rows */
        $rows = $this->executor->fetchAll('SELECT id, content, created_at FROM notes ORDER BY id');

        return array_map(
            static fn(array $row): Note => new Note(
                id: $row['id'],
                content: $row['content'],
                createdAt: $row['created_at'],
            ),
            $rows,
        );
    }
}
