<?php

declare(strict_types=1);

namespace Upload\Upload;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class SqliteUploadRepository
{
    public function __construct(
        private readonly DatabaseQueryExecutorInterface $db,
        private readonly string $storageDir,
    ) {
    }

    public function store(
        string $bytes,
        string $mimeType,
        int $sizeBytes,
        string $originalFilename,
        string $storedFilename,
    ): UploadedFile {
        $now  = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $path = $this->storageDir . '/' . $storedFilename;

        file_put_contents($path, $bytes);

        $id = $this->db->insert(
            'INSERT INTO uploads (original_filename, stored_filename, mime_type, size_bytes, uploaded_at) VALUES (?, ?, ?, ?, ?)',
            [$originalFilename, $storedFilename, $mimeType, $sizeBytes, $now],
        );

        return new UploadedFile($id, $originalFilename, $storedFilename, $mimeType, $sizeBytes, $now);
    }

    public function findById(int $id): ?UploadedFile
    {
        $row = $this->db->fetchOne(
            'SELECT id, original_filename, stored_filename, mime_type, size_bytes, uploaded_at FROM uploads WHERE id = ?',
            [$id],
        );

        return $row !== null ? $this->hydrate($row) : null;
    }

    /** @return list<UploadedFile> */
    public function listAll(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, original_filename, stored_filename, mime_type, size_bytes, uploaded_at FROM uploads ORDER BY id',
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function delete(int $id): bool
    {
        $file = $this->findById($id);
        if ($file === null) {
            return false;
        }

        $path = $this->storageDir . '/' . $file->storedFilename;
        if (file_exists($path)) {
            unlink($path);
        }

        $this->db->execute('DELETE FROM uploads WHERE id = ?', [$id]);

        return true;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): UploadedFile
    {
        return new UploadedFile(
            id:               (int) $row['id'],
            originalFilename: (string) $row['original_filename'],
            storedFilename:   (string) $row['stored_filename'],
            mimeType:         (string) $row['mime_type'],
            sizeBytes:        (int) $row['size_bytes'],
            uploadedAt:       (string) $row['uploaded_at'],
        );
    }
}
