<?php

declare(strict_types=1);

namespace Signed\SignedUrl;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class FileRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {
    }

    public function create(string $name, string $mimeType, int $sizeBytes, int $ownerId, string $now): FileRecord
    {
        $this->executor->execute(
            'INSERT INTO files (name, mime_type, size_bytes, owner_id, created_at) VALUES (?, ?, ?, ?, ?)',
            [$name, $mimeType, $sizeBytes, $ownerId, $now],
        );
        $id = (int) $this->executor->lastInsertId();

        return new FileRecord($id, $name, $mimeType, $sizeBytes, $ownerId, $now);
    }

    public function findById(int $id): ?FileRecord
    {
        $rows = $this->executor->fetchAll('SELECT * FROM files WHERE id = ?', [$id]);
        if ($rows === []) {
            return null;
        }

        return $this->hydrate($rows[0]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): FileRecord
    {
        return new FileRecord(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['mime_type'],
            (int) $row['size_bytes'],
            (int) $row['owner_id'],
            (string) $row['created_at'],
        );
    }
}
