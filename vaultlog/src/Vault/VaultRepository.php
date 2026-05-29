<?php

declare(strict_types=1);

namespace VaultLog\Vault;

use Nene2\Database\DatabaseQueryExecutorInterface;

class VaultRepository
{
    public function __construct(
        private readonly DatabaseQueryExecutorInterface $db,
        private readonly string $hmacSecret,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function findEntry(int $userId, string $key): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, user_id, key_name, value, hmac, created_at, updated_at
             FROM vault_entries WHERE user_id = ? AND key_name = ?',
            [$userId, $key],
        );
    }

    /** @return list<string> the user's own key names */
    public function listKeys(int $userId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->fetchAll(
            'SELECT key_name FROM vault_entries WHERE user_id = ? ORDER BY key_name ASC',
            [$userId],
        );
        return array_map(static fn (array $r): string => (string) $r['key_name'], $rows);
    }

    /**
     * Upsert a secret.
     *
     * @return 'stored'|'updated' 'stored' on first write (201), 'updated' on overwrite (200)
     */
    public function store(int $userId, string $key, string $value, string $now): string
    {
        $hmac = $this->computeHmac($userId, $key, $value);

        if ($this->findEntry($userId, $key) !== null) {
            $this->db->execute(
                'UPDATE vault_entries SET value = ?, hmac = ?, updated_at = ? WHERE user_id = ? AND key_name = ?',
                [$value, $hmac, $now, $userId, $key],
            );
            return 'updated';
        }

        $this->db->execute(
            'INSERT INTO vault_entries (user_id, key_name, value, hmac, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $key, $value, $hmac, $now, $now],
        );
        return 'stored';
    }

    public function delete(int $userId, string $key): bool
    {
        return $this->db->execute(
            'DELETE FROM vault_entries WHERE user_id = ? AND key_name = ?',
            [$userId, $key],
        ) > 0;
    }

    /** @return list<array<string, mixed>> all (user_id, key_name) — admin metadata, never values */
    public function adminListAll(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT user_id, key_name FROM vault_entries ORDER BY user_id ASC, key_name ASC',
        );
    }

    /** @return list<string> a specific user's key names (admin metadata) */
    public function adminListUser(int $userId): array
    {
        return $this->listKeys($userId);
    }

    /**
     * Recompute the HMAC over the stored fields and compare in constant time.
     * Detects out-of-band DB tampering of `value`.
     *
     * @param array<string, mixed> $entry
     */
    public function verifyIntegrity(array $entry): bool
    {
        $expected = $this->computeHmac((int) $entry['user_id'], (string) $entry['key_name'], (string) $entry['value']);
        return hash_equals($expected, (string) $entry['hmac']);
    }

    private function computeHmac(int $userId, string $key, string $value): string
    {
        return hash_hmac('sha256', "{$userId}|{$key}|{$value}", $this->hmacSecret);
    }
}
