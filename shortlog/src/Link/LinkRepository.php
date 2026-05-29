<?php

declare(strict_types=1);

namespace ShortLog\Link;

use Nene2\Database\DatabaseConstraintException;
use Nene2\Database\DatabaseQueryExecutorInterface;

class LinkRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, user_id, slug, url, click_count, created_at FROM links WHERE slug = ?',
            [$slug],
        );
    }

    /** @return list<array<string, mixed>> */
    public function listOwned(int $userId, int $limit): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, user_id, slug, url, click_count, created_at FROM links WHERE user_id = ? ORDER BY id DESC LIMIT ?',
            [$userId, $limit],
        );
    }

    /** Returns the slug, or null if it collided (caller retries). */
    public function create(int $userId, string $slug, string $url, string $now): ?string
    {
        try {
            // click_count defaults to 0 server-side — never taken from the request.
            $this->db->execute(
                'INSERT INTO links (user_id, slug, url, click_count, created_at) VALUES (?, ?, ?, 0, ?)',
                [$userId, $slug, $url, $now],
            );
        } catch (DatabaseConstraintException) {
            return null;
        }
        return $slug;
    }

    public function deleteOwned(string $slug, int $userId): bool
    {
        return $this->db->execute('DELETE FROM links WHERE slug = ? AND user_id = ?', [$slug, $userId]) > 0;
    }
}
