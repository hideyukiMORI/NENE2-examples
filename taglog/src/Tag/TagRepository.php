<?php

declare(strict_types=1);

namespace Tag\Tag;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class TagRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {
    }

    public function createPost(string $title, string $body, string $now): Post
    {
        $this->executor->execute(
            'INSERT INTO posts (title, body, created_at) VALUES (?, ?, ?)',
            [$title, $body, $now],
        );

        $row = $this->executor->fetchOne('SELECT * FROM posts ORDER BY id DESC LIMIT 1', []);

        return $this->hydratePost((array) $row, []);
    }

    public function findPostById(int $id): ?Post
    {
        $row = $this->executor->fetchOne('SELECT * FROM posts WHERE id = ?', [$id]);

        if ($row === null) {
            return null;
        }

        $tags = $this->findTagsByPostId($id);

        return $this->hydratePost($row, $tags);
    }

    public function createTag(string $name, string $now): ?Tag
    {
        try {
            $this->executor->execute(
                'INSERT INTO tags (name, created_at) VALUES (?, ?)',
                [$name, $now],
            );
        } catch (\RuntimeException) {
            return null;
        }

        return $this->findTagByName($name);
    }

    public function findTagByName(string $name): ?Tag
    {
        $row = $this->executor->fetchOne('SELECT * FROM tags WHERE name = ?', [$name]);

        return $row !== null ? $this->hydrateTag($row) : null;
    }

    /** @return Tag[] */
    public function findAllTags(): array
    {
        $rows = $this->executor->fetchAll('SELECT * FROM tags ORDER BY name ASC', []);

        return array_map($this->hydrateTag(...), $rows);
    }

    /**
     * Set tags for a post atomically: delete all existing post_tags, then insert the new set.
     *
     * @param  string[] $tagNames
     * @return Tag[]
     */
    public function setPostTags(int $postId, array $tagNames): array
    {
        $this->executor->execute('DELETE FROM post_tags WHERE post_id = ?', [$postId]);

        foreach ($tagNames as $name) {
            $tag = $this->findTagByName($name);
            if ($tag === null) {
                continue;
            }

            $this->executor->execute(
                'INSERT OR IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)',
                [$postId, $tag->id],
            );
        }

        return $this->findTagsByPostId($postId);
    }

    /** @return Post[] */
    public function findPostsByTag(string $tagName): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT p.* FROM posts p
             INNER JOIN post_tags pt ON pt.post_id = p.id
             INNER JOIN tags t ON t.id = pt.tag_id
             WHERE t.name = ?
             ORDER BY p.id DESC',
            [$tagName],
        );

        if ($rows === []) {
            return [];
        }

        $postIds  = array_column($rows, 'id');
        $tagsMap  = $this->findTagsByPostIds($postIds);

        return array_map(
            fn (array $row) => $this->hydratePost($row, $tagsMap[(int) $row['id']] ?? []),
            $rows,
        );
    }

    /** @return Tag[] */
    private function findTagsByPostId(int $postId): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT t.* FROM tags t
             INNER JOIN post_tags pt ON pt.tag_id = t.id
             WHERE pt.post_id = ?
             ORDER BY t.name ASC',
            [$postId],
        );

        return array_map($this->hydrateTag(...), $rows);
    }

    /**
     * Fetch tags for multiple posts in a single query to avoid N+1.
     *
     * @param  int[]           $postIds
     * @return array<int, Tag[]>
     */
    private function findTagsByPostIds(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $rows         = $this->executor->fetchAll(
            "SELECT t.*, pt.post_id FROM tags t
             INNER JOIN post_tags pt ON pt.tag_id = t.id
             WHERE pt.post_id IN ({$placeholders})
             ORDER BY t.name ASC",
            $postIds,
        );

        $map = [];
        foreach ($rows as $row) {
            $postId        = (int) $row['post_id'];
            $map[$postId][] = $this->hydrateTag($row);
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $row
     * @param Tag[]                $tags
     */
    private function hydratePost(array $row, array $tags): Post
    {
        return new Post(
            id:        (int) $row['id'],
            title:     (string) $row['title'],
            body:      (string) $row['body'],
            createdAt: (string) $row['created_at'],
            tags:      $tags,
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateTag(array $row): Tag
    {
        return new Tag(
            id:        (int) $row['id'],
            name:      (string) $row['name'],
            createdAt: (string) $row['created_at'],
        );
    }
}
