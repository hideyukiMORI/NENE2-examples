<?php

declare(strict_types=1);

namespace Comment\Comment;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class CommentRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {
    }

    public function createPost(string $title, string $now): Post
    {
        $this->executor->execute(
            'INSERT INTO posts (title, created_at) VALUES (?, ?)',
            [$title, $now],
        );

        $row = $this->executor->fetchOne('SELECT * FROM posts ORDER BY id DESC LIMIT 1', []);

        return $this->hydratePost((array) $row);
    }

    public function findPostById(int $id): ?Post
    {
        $row = $this->executor->fetchOne('SELECT * FROM posts WHERE id = ?', [$id]);

        return $row !== null ? $this->hydratePost($row) : null;
    }

    public function addComment(int $postId, ?int $parentId, string $authorName, string $body, int $depth, string $now): Comment
    {
        $this->executor->execute(
            'INSERT INTO comments (post_id, parent_id, author_name, body, status, depth, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$postId, $parentId, $authorName, $body, 'published', $depth, $now],
        );

        $row = $this->executor->fetchOne('SELECT * FROM comments ORDER BY id DESC LIMIT 1', []);

        return $this->hydrateComment((array) $row, []);
    }

    public function findCommentById(int $id): ?Comment
    {
        $row = $this->executor->fetchOne('SELECT * FROM comments WHERE id = ?', [$id]);

        return $row !== null ? $this->hydrateComment($row, []) : null;
    }

    public function softDelete(int $id): void
    {
        $this->executor->execute(
            "UPDATE comments SET status = 'deleted', body = '[deleted]' WHERE id = ?",
            [$id],
        );
    }

    /**
     * Load full comment tree for a post in a single query, then assemble in PHP.
     *
     * Keeps raw rows and child-ID lists separate from Comment objects to
     * satisfy PHPStan's strict type checking on readonly value objects.
     *
     * @return Comment[]
     */
    public function findCommentTree(int $postId): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM comments WHERE post_id = ? ORDER BY id ASC',
            [$postId],
        );

        // Step 1: raw row map and child-ID adjacency list (no Comment objects yet)
        /** @var array<int, array<string, mixed>> $rowMap */
        $rowMap = [];
        /** @var array<int, int[]> $childIds */
        $childIds = [];
        $roots    = [];

        foreach ($rows as $row) {
            $id            = (int) $row['id'];
            $rowMap[$id]   = $row;
            $childIds[$id] = [];
        }

        foreach ($rowMap as $id => $row) {
            $parentId = isset($row['parent_id']) ? (int) $row['parent_id'] : null;
            if ($parentId === null) {
                $roots[] = $id;
            } elseif (isset($childIds[$parentId])) {
                $childIds[$parentId][] = $id;
            }
        }

        // Step 2: recursively build Comment objects (parents always before children due to ORDER BY id ASC)
        return $this->buildTree($roots, $rowMap, $childIds);
    }

    /**
     * @param  int[]                              $ids
     * @param  array<int, array<string, mixed>>   $rowMap
     * @param  array<int, int[]>                  $childIds
     * @return Comment[]
     */
    private function buildTree(array $ids, array $rowMap, array $childIds): array
    {
        $result = [];
        foreach ($ids as $id) {
            $children = $this->buildTree($childIds[$id], $rowMap, $childIds);
            $result[] = $this->hydrateComment($rowMap[$id], $children);
        }

        return $result;
    }

    /** @param array<string, mixed> $row */
    private function hydratePost(array $row): Post
    {
        return new Post(
            id:        (int) $row['id'],
            title:     (string) $row['title'],
            createdAt: (string) $row['created_at'],
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param Comment[]            $children
     */
    private function hydrateComment(array $row, array $children): Comment
    {
        return new Comment(
            id:         (int) $row['id'],
            postId:     (int) $row['post_id'],
            parentId:   isset($row['parent_id']) ? (int) $row['parent_id'] : null,
            authorName: (string) $row['author_name'],
            body:       (string) $row['body'],
            status:     (string) $row['status'],
            depth:      (int) $row['depth'],
            createdAt:  (string) $row['created_at'],
            children:   $children,
        );
    }
}
