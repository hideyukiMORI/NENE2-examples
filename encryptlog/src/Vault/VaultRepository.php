<?php

declare(strict_types=1);

namespace EncryptLog\Vault;

use Nene2\Database\DatabaseQueryExecutorInterface;

class VaultRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findOwned(int $id, int $userId): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, user_id, email_enc, email_idx, note_enc, created_at, updated_at FROM records WHERE id = ? AND user_id = ?',
            [$id, $userId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function listOwned(int $userId, int $limit): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, user_id, email_enc, email_idx, note_enc, created_at, updated_at FROM records WHERE user_id = ? ORDER BY id DESC LIMIT ?',
            [$userId, $limit],
        );
    }

    /** @return list<array<string, mixed>> equality search via blind index */
    public function searchByIndex(int $userId, string $emailIdx): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, user_id, email_enc, email_idx, note_enc, created_at, updated_at FROM records WHERE user_id = ? AND email_idx = ?',
            [$userId, $emailIdx],
        );
    }

    public function create(int $userId, string $emailEnc, string $emailIdx, string $noteEnc, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO records (user_id, email_enc, email_idx, note_enc, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $emailEnc, $emailIdx, $noteEnc, $now, $now],
        );
    }

    public function update(int $id, int $userId, string $emailEnc, string $emailIdx, string $noteEnc, string $now): void
    {
        $this->db->execute(
            'UPDATE records SET email_enc = ?, email_idx = ?, note_enc = ?, updated_at = ? WHERE id = ? AND user_id = ?',
            [$emailEnc, $emailIdx, $noteEnc, $now, $id, $userId],
        );
    }

    public function delete(int $id, int $userId): bool
    {
        return $this->db->execute('DELETE FROM records WHERE id = ? AND user_id = ?', [$id, $userId]) > 0;
    }
}
