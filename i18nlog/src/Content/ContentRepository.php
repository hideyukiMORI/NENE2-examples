<?php

declare(strict_types=1);

namespace I18nLog\Content;

use Nene2\Database\DatabaseQueryExecutorInterface;

class ContentRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findArticle(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, default_locale, published, created_at, updated_at FROM articles WHERE id = ?',
            [$id],
        );
    }

    /** @return list<array<string, mixed>> published only */
    public function listPublished(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, default_locale, published, created_at, updated_at FROM articles WHERE published = 1 ORDER BY id ASC',
        );
    }

    public function createArticle(string $defaultLocale, bool $published, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO articles (default_locale, published, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$defaultLocale, $published ? 1 : 0, $now, $now],
        );
    }

    /** @return array<string, mixed>|null exact-locale translation */
    public function translation(int $articleId, string $locale): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT locale, title, body, updated_at FROM translations WHERE article_id = ? AND locale = ?',
            [$articleId, $locale],
        );
    }

    /** @return list<string> available locales for an article */
    public function locales(int $articleId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->fetchAll('SELECT locale FROM translations WHERE article_id = ? ORDER BY locale ASC', [$articleId]);
        return array_map(static fn (array $r): string => (string) $r['locale'], $rows);
    }

    /**
     * Upsert a translation (last-write-wins).
     *
     * @return 'created'|'updated'
     */
    public function upsertTranslation(int $articleId, string $locale, string $title, string $body, string $now): string
    {
        if ($this->translation($articleId, $locale) !== null) {
            $this->db->execute(
                'UPDATE translations SET title = ?, body = ?, updated_at = ? WHERE article_id = ? AND locale = ?',
                [$title, $body, $now, $articleId, $locale],
            );
            return 'updated';
        }
        $this->db->execute(
            'INSERT INTO translations (article_id, locale, title, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$articleId, $locale, $title, $body, $now, $now],
        );
        return 'created';
    }
}
