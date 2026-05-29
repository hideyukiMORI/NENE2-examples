<?php

declare(strict_types=1);

namespace ReorderLog\Reorder;

use Nene2\Database\DatabaseQueryExecutorInterface;

class ReorderRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findBoard(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne('SELECT id, owner_id, name FROM boards WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function listItems(int $boardId): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, title, position FROM items WHERE board_id = ? ORDER BY position ASC',
            [$boardId],
        );
    }

    /**
     * The ids currently belonging to a board (ascending).
     *
     * @return list<int>
     */
    public function itemIds(int $boardId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->fetchAll('SELECT id FROM items WHERE board_id = ? ORDER BY id ASC', [$boardId]);
        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }
}
