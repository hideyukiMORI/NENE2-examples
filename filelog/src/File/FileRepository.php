<?php

declare(strict_types=1);

namespace FileLog\File;

use Nene2\Database\DatabaseQueryExecutorInterface;

class FileRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findUserById(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne('SELECT id, name FROM users WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findFileById(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT f.id, f.user_id, f.name, f.size, f.mime_type, f.description,
                    f.visibility, f.created_at, f.updated_at,
                    u.name AS owner_name
             FROM files f
             JOIN users u ON u.id = f.user_id
             WHERE f.id = ?',
            [$id]
        );
    }

    /** @return array<string, mixed>|null */
    public function findShare(int $fileId, int $userId): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, file_id, shared_with_user_id, can_edit FROM file_shares
             WHERE file_id = ? AND shared_with_user_id = ?',
            [$fileId, $userId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function listAccessibleFiles(int $userId): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT f.id, f.user_id, f.name, f.size, f.mime_type, f.description,
                    f.visibility, f.created_at, f.updated_at,
                    u.name AS owner_name,
                    CASE WHEN f.user_id = ? THEN 1 ELSE fs.can_edit END AS can_edit,
                    CASE WHEN f.user_id = ? THEN 1 ELSE 0 END AS is_owner
             FROM files f
             JOIN users u ON u.id = f.user_id
             LEFT JOIN file_shares fs ON fs.file_id = f.id AND fs.shared_with_user_id = ?
             WHERE f.user_id = ? OR fs.shared_with_user_id = ?
             ORDER BY f.created_at DESC, f.id DESC',
            [$userId, $userId, $userId, $userId, $userId]
        );
    }

    public function create(
        int $userId,
        string $name,
        int $size,
        string $mimeType,
        ?string $description,
        string $visibility,
        string $now,
    ): int {
        return $this->db->insert(
            'INSERT INTO files (user_id, name, size, mime_type, description, visibility, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $name, $size, $mimeType, $description, $visibility, $now, $now]
        );
    }

    public function update(
        int $id,
        string $name,
        int $size,
        string $mimeType,
        ?string $description,
        string $visibility,
        string $now,
    ): void {
        $this->db->execute(
            'UPDATE files SET name = ?, size = ?, mime_type = ?, description = ?,
             visibility = ?, updated_at = ? WHERE id = ?',
            [$name, $size, $mimeType, $description, $visibility, $now, $id]
        );
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM file_shares WHERE file_id = ?', [$id]);
        $this->db->execute('DELETE FROM files WHERE id = ?', [$id]);
    }

    public function addShare(int $fileId, int $userId, bool $canEdit, string $now): void
    {
        $this->db->execute(
            'INSERT INTO file_shares (file_id, shared_with_user_id, can_edit, created_at)
             VALUES (?, ?, ?, ?)',
            [$fileId, $userId, $canEdit ? 1 : 0, $now]
        );
    }

    public function removeShare(int $fileId, int $userId): void
    {
        $this->db->execute(
            'DELETE FROM file_shares WHERE file_id = ? AND shared_with_user_id = ?',
            [$fileId, $userId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function listShares(int $fileId): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT fs.id, fs.shared_with_user_id, fs.can_edit, fs.created_at, u.name AS user_name
             FROM file_shares fs
             JOIN users u ON u.id = fs.shared_with_user_id
             WHERE fs.file_id = ?
             ORDER BY fs.created_at ASC',
            [$fileId]
        );
    }
}
