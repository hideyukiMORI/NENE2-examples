<?php

declare(strict_types=1);

namespace Hierarchylog\Category;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class CategoryRepository
{
    public const int MAX_DEPTH = 5;

    public function __construct(
        private DatabaseQueryExecutorInterface $db,
    ) {
    }

    public function create(string $name, ?int $parentId, string $now): Category
    {
        if ($parentId !== null) {
            $parent = $this->findById($parentId);
            if ($parent === null) {
                throw new CategoryNotFoundException($parentId);
            }
            if ($parent->depth >= self::MAX_DEPTH - 1) {
                throw new CategoryDepthException($parent->depth + 1, self::MAX_DEPTH);
            }
            $parentPath = $parent->path;
            $depth      = $parent->depth + 1;
        } else {
            $parentPath = '/';
            $depth      = 0;
        }

        $id   = $this->db->insert(
            'INSERT INTO categories (name, parent_id, path, depth, created_at) VALUES (?, ?, ?, ?, ?)',
            [$name, $parentId, '__tmp__', $depth, $now],
        );
        $path = $parentPath . $id . '/';
        $this->db->execute('UPDATE categories SET path = ? WHERE id = ?', [$path, $id]);

        return new Category($id, $name, $parentId, $path, $depth, $now);
    }

    /** @return list<Category> */
    public function listByParent(?int $parentId): array
    {
        if ($parentId === null) {
            $rows = $this->db->fetchAll(
                'SELECT * FROM categories WHERE parent_id IS NULL ORDER BY id',
                [],
            );
        } else {
            $rows = $this->db->fetchAll(
                'SELECT * FROM categories WHERE parent_id = ? ORDER BY id',
                [$parentId],
            );
        }

        return array_map($this->hydrate(...), $rows);
    }

    public function findById(int $id): ?Category
    {
        $row = $this->db->fetchOne('SELECT * FROM categories WHERE id = ?', [$id]);

        return $row !== null ? $this->hydrate($row) : null;
    }

    /**
     * Returns all descendants (children, grandchildren, …) ordered by path.
     *
     * Uses the materialized path: all nodes whose path starts with the root's path.
     * The root node itself is excluded.
     *
     * @return list<Category>
     */
    public function subtree(int $id): array
    {
        $root = $this->findById($id);
        if ($root === null) {
            throw new CategoryNotFoundException($id);
        }

        $rows = $this->db->fetchAll(
            "SELECT * FROM categories WHERE path LIKE ? AND id != ? ORDER BY path",
            [$root->path . '%', $id],
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Returns the chain of ancestors from root down to (not including) this node.
     *
     * @return list<Category>
     */
    public function ancestors(int $id): array
    {
        $node = $this->findById($id);
        if ($node === null) {
            return [];
        }

        // Path "/1/3/7/" → ancestor ids [1, 3]
        $parts = array_filter(explode('/', $node->path));
        $ancestorIds = array_values(array_filter(
            array_map('intval', $parts),
            static fn (int $pid): bool => $pid !== $id,
        ));

        if ($ancestorIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ancestorIds), '?'));
        $rows = $this->db->fetchAll(
            "SELECT * FROM categories WHERE id IN ({$placeholders}) ORDER BY depth",
            $ancestorIds,
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function updateName(int $id, string $name): bool
    {
        return $this->db->execute(
            'UPDATE categories SET name = ? WHERE id = ?',
            [$name, $id],
        ) > 0;
    }

    /**
     * Moves a node (and all its descendants) to a new parent.
     *
     * Validates:
     *  - new parent exists (or null for root)
     *  - new parent is not the node itself
     *  - new parent is not a descendant of the node (circular reference)
     *  - resulting depth does not exceed MAX_DEPTH
     */
    public function move(int $id, ?int $newParentId, string $now): Category
    {
        $node = $this->findById($id);
        if ($node === null) {
            throw new CategoryNotFoundException($id);
        }

        // Self-move guard
        if ($newParentId === $id) {
            throw new CategoryCircularException('Cannot move a category under itself.');
        }

        if ($newParentId !== null) {
            $newParent = $this->findById($newParentId);
            if ($newParent === null) {
                throw new CategoryNotFoundException($newParentId);
            }

            // Circular reference: new parent must not be a descendant of the node
            if (str_starts_with($newParent->path, $node->path)) {
                throw new CategoryCircularException('Cannot move a category under one of its descendants.');
            }

            $newDepth = $newParent->depth + 1;

            // Check subtree will not exceed MAX_DEPTH
            $subtree      = $this->subtree($id);
            $maxSubDepth  = $subtree !== []
                ? max(array_map(static fn (Category $c): int => $c->depth - $node->depth, $subtree))
                : 0;
            if ($newDepth + $maxSubDepth >= self::MAX_DEPTH) {
                throw new CategoryDepthException($newDepth + $maxSubDepth, self::MAX_DEPTH);
            }

            $newParentPath = $newParent->path;
        } else {
            $newDepth      = 0;
            $newParentPath = '/';
        }

        $oldPath = $node->path;
        $newPath = $newParentPath . $id . '/';

        // Update node itself
        $this->db->execute(
            'UPDATE categories SET parent_id = ?, path = ?, depth = ? WHERE id = ?',
            [$newParentId, $newPath, $newDepth, $id],
        );

        // Cascade path/depth update to all descendants
        $descendants = $this->subtree($id);
        foreach ($descendants as $desc) {
            $updatedPath  = $newPath . substr($desc->path, strlen($oldPath));
            $updatedDepth = $desc->depth - $node->depth + $newDepth;
            $this->db->execute(
                'UPDATE categories SET path = ?, depth = ? WHERE id = ?',
                [$updatedPath, $updatedDepth, $desc->id],
            );
        }

        return $this->findById($id) ?? throw new CategoryNotFoundException($id);
    }

    /**
     * Deletes a leaf node. Raises CategoryHasChildrenException if it has children.
     */
    public function delete(int $id): bool
    {
        $node = $this->findById($id);
        if ($node === null) {
            return false;
        }

        $children = $this->listByParent($id);
        if ($children !== []) {
            throw new CategoryHasChildrenException($id, count($children));
        }

        return $this->db->execute('DELETE FROM categories WHERE id = ?', [$id]) > 0;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Category
    {
        return new Category(
            (int) $row['id'],
            (string) $row['name'],
            $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            (string) $row['path'],
            (int) $row['depth'],
            (string) $row['created_at'],
        );
    }
}
