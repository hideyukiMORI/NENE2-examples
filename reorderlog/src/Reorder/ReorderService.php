<?php

declare(strict_types=1);

namespace ReorderLog\Reorder;

use Nene2\Database\DatabaseTransactionManagerInterface;

/**
 * Reorders a board's items in a single transaction.
 *
 * Why two phases? With `UNIQUE (board_id, position)`, SQLite checks the
 * constraint **per row** as an UPDATE is applied — so even one statement that
 * swaps positions (e.g. 0↔2) transiently produces a duplicate and fails. The
 * fix is to first shift every position into a collision-free range (negate it)
 * and only then write the final positions. Both UPDATEs run inside one
 * transaction so the intermediate negative state is never observable.
 */
final class ReorderService
{
    public function __construct(
        private readonly DatabaseTransactionManagerInterface $tx,
    ) {
    }

    /**
     * @param list<int> $orderedIds  the board's ids in their new display order
     */
    public function reorder(int $boardId, array $orderedIds): void
    {
        $this->tx->transactional(function ($executor) use ($boardId, $orderedIds): void {
            // Phase 1: move every position to a unique negative value so no
            // two rows collide while the final positions are assigned.
            $executor->execute(
                'UPDATE items SET position = -1 - position WHERE board_id = ?',
                [$boardId],
            );

            // Phase 2: assign final positions from the array index. The client
            // never supplies position numbers — only the order of ids.
            $cases = '';
            $params = [];
            foreach ($orderedIds as $position => $id) {
                $cases .= ' WHEN id = ? THEN ?';
                $params[] = $id;
                $params[] = $position;
            }

            $placeholders = implode(',', array_fill(0, count($orderedIds), '?'));
            $executor->execute(
                "UPDATE items SET position = CASE{$cases} END WHERE board_id = ? AND id IN ({$placeholders})",
                [...$params, $boardId, ...$orderedIds],
            );
        });
    }
}
