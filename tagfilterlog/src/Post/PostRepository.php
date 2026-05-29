<?php

declare(strict_types=1);

namespace TagFilterLog\Post;

use Nene2\Database\DatabaseQueryExecutorInterface;
use Nene2\Database\DatabaseTransactionManagerInterface;

class PostRepository
{
    public function __construct(
        private readonly DatabaseQueryExecutorInterface $db,
        private readonly DatabaseTransactionManagerInterface $tx,
    ) {
    }

    /**
     * Create a post and its tags atomically. Tags are deduplicated and sorted in
     * PHP; INSERT OR IGNORE is a second-layer guard against the composite PK.
     *
     * @param list<string> $tags
     */
    public function create(string $title, string $body, array $tags, string $now): Post
    {
        $uniqueTags = array_values(array_unique($tags));
        sort($uniqueTags);

        $postId = 0;
        $this->tx->transactional(function (DatabaseQueryExecutorInterface $tx) use (&$postId, $title, $body, $uniqueTags, $now): void {
            $postId = $tx->insert('INSERT INTO posts (title, body, created_at) VALUES (?, ?, ?)', [$title, $body, $now]);
            foreach ($uniqueTags as $tag) {
                $tx->insert('INSERT OR IGNORE INTO post_tags (post_id, tag) VALUES (?, ?)', [$postId, $tag]);
            }
        });

        return new Post($postId, $title, $body, $uniqueTags, $now);
    }

    public function find(int $id): ?Post
    {
        $row = $this->db->fetchOne('SELECT * FROM posts WHERE id = ?', [$id]);
        return $row !== null ? $this->hydrate($row) : null;
    }

    /** @return list<Post> */
    public function findAll(): array
    {
        return array_map($this->hydrate(...), $this->db->fetchAll('SELECT * FROM posts ORDER BY created_at DESC, id DESC', []));
    }

    /**
     * AND semantics: posts that have *all* of the given tags.
     *
     * @param list<string> $tags
     * @return list<Post>
     */
    public function findByAllTags(array $tags): array
    {
        if ($tags === []) {
            return $this->findAll();
        }
        $placeholders = implode(',', array_fill(0, count($tags), '?'));
        $rows = $this->db->fetchAll(
            "SELECT p.* FROM posts p
             INNER JOIN post_tags pt ON pt.post_id = p.id
             WHERE pt.tag IN ({$placeholders})
             GROUP BY p.id
             HAVING COUNT(DISTINCT pt.tag) = CAST(? AS INTEGER)
             ORDER BY p.created_at DESC, p.id DESC",
            [...$tags, count($tags)],
        );
        return array_map($this->hydrate(...), $rows);
    }

    /**
     * OR semantics: posts that have *any* of the given tags.
     *
     * @param list<string> $tags
     * @return list<Post>
     */
    public function findByAnyTag(array $tags): array
    {
        if ($tags === []) {
            return $this->findAll();
        }
        $placeholders = implode(',', array_fill(0, count($tags), '?'));
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT p.* FROM posts p
             INNER JOIN post_tags pt ON pt.post_id = p.id
             WHERE pt.tag IN ({$placeholders})
             ORDER BY p.created_at DESC, p.id DESC",
            $tags,
        );
        return array_map($this->hydrate(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Post
    {
        $id = (int) $row['id'];
        $tagRows = $this->db->fetchAll('SELECT tag FROM post_tags WHERE post_id = ? ORDER BY tag ASC', [$id]);
        $tags = array_map(static fn (array $r): string => (string) $r['tag'], $tagRows);
        return new Post($id, (string) $row['title'], (string) $row['body'], $tags, (string) $row['created_at']);
    }
}
