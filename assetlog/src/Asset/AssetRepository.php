<?php

declare(strict_types=1);

namespace AssetLog\Asset;

use Nene2\Database\DatabaseQueryExecutorInterface;

class AssetRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, name, holder_id, created_at, updated_at FROM assets WHERE id = ?',
            [$id],
        );
    }

    /** @return list<array<string, mixed>> */
    public function listAll(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, name, holder_id, created_at, updated_at FROM assets ORDER BY id ASC',
        );
    }

    public function create(string $name, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO assets (name, holder_id, created_at, updated_at) VALUES (?, NULL, ?, ?)',
            [$name, $now, $now],
        );
    }

    /**
     * Exclusive checkout. The `WHERE holder_id IS NULL` guard makes the hold
     * atomic — a second concurrent checkout updates 0 rows and is rejected.
     *
     * @return 'not_found'|'unavailable'|'success'
     */
    public function checkout(int $assetId, int $userId, string $now): string
    {
        if ($this->findById($assetId) === null) {
            return 'not_found';
        }

        $affected = $this->db->execute(
            'UPDATE assets SET holder_id = ?, updated_at = ? WHERE id = ? AND holder_id IS NULL',
            [$userId, $now, $assetId],
        );
        if ($affected === 0) {
            return 'unavailable';
        }

        $this->appendHistory($assetId, $userId, 'checkout', $now);
        return 'success';
    }

    /**
     * @return 'not_found'|'already_available'|'not_holder'|'success'
     */
    public function checkin(int $assetId, int $userId, string $now): string
    {
        $asset = $this->findById($assetId);
        if ($asset === null) {
            return 'not_found';
        }
        if ($asset['holder_id'] === null) {
            return 'already_available';
        }
        if ((int) $asset['holder_id'] !== $userId) {
            return 'not_holder';
        }

        $this->db->execute(
            'UPDATE assets SET holder_id = NULL, updated_at = ? WHERE id = ? AND holder_id = ?',
            [$now, $assetId, $userId],
        );
        $this->appendHistory($assetId, $userId, 'checkin', $now);
        return 'success';
    }

    /** @return list<array<string, mixed>> */
    public function history(int $assetId): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, asset_id, user_id, action, acted_at FROM asset_history WHERE asset_id = ? ORDER BY id ASC',
            [$assetId],
        );
    }

    private function appendHistory(int $assetId, int $userId, string $action, string $now): void
    {
        $this->db->execute(
            'INSERT INTO asset_history (asset_id, user_id, action, acted_at) VALUES (?, ?, ?, ?)',
            [$assetId, $userId, $action, $now],
        );
    }
}
