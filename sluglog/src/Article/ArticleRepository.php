<?php

declare(strict_types=1);

namespace Sluglog\Article;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class ArticleRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $db,
    ) {
    }

    /**
     * Creates an article with a unique slug derived from the title.
     *
     * If the generated slug is already taken, appends -2, -3, … until unique.
     */
    public function create(string $title, string $body, string $now): Article
    {
        $base = SlugHelper::fromTitle($title);
        $slug = SlugHelper::makeUnique($base, fn (string $s): bool => $this->slugExists($s));

        $id = $this->db->insert(
            'INSERT INTO articles (title, slug, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$title, $slug, $body, $now, $now],
        );

        return new Article($id, $title, $slug, $body, $now, $now);
    }

    /**
     * Finds an article by its current slug.
     * Does NOT search slug history — use findBySlugWithRedirect for that.
     */
    public function findBySlug(string $slug): ?Article
    {
        $row = $this->db->fetchOne('SELECT * FROM articles WHERE slug = ?', [$slug]);

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function findById(int $id): ?Article
    {
        $row = $this->db->fetchOne('SELECT * FROM articles WHERE id = ?', [$id]);

        return $row !== null ? $this->hydrate($row) : null;
    }

    /**
     * Looks up a slug in both the current slug column and slug history.
     *
     * Returns:
     *   - ['found' => Article, 'redirect' => false] — slug is current
     *   - ['found' => Article, 'redirect' => true]  — slug is historical; client should 301
     *   - null                                       — not found at all
     *
     * @return array{found: Article, redirect: bool}|null
     */
    public function findBySlugWithRedirect(string $slug): ?array
    {
        // Check current slug first
        $article = $this->findBySlug($slug);
        if ($article !== null) {
            return ['found' => $article, 'redirect' => false];
        }

        // Check slug history
        $historyRow = $this->db->fetchOne(
            'SELECT article_id FROM slug_history WHERE old_slug = ?',
            [$slug],
        );

        if ($historyRow === null) {
            return null;
        }

        $article = $this->findById((int) $historyRow['article_id']);
        if ($article === null) {
            return null;
        }

        return ['found' => $article, 'redirect' => true];
    }

    /**
     * Updates the article's title, body, and optionally its slug.
     *
     * If a new slug is requested:
     *  - The old slug is saved to slug_history.
     *  - The new slug is made unique if needed.
     * If no new slug is provided, the slug is re-derived from the new title and changed
     * only if different from the current slug.
     *
     * @param string|null $explicitSlug  Caller-provided slug; null = derive from title.
     */
    public function update(int $id, string $title, string $body, ?string $explicitSlug, string $now): Article
    {
        $article = $this->findById($id);
        if ($article === null) {
            throw new ArticleNotFoundException($id);
        }

        $newSlugBase = $explicitSlug !== null
            ? SlugHelper::fromTitle($explicitSlug)
            : SlugHelper::fromTitle($title);

        // Compute the unique new slug (ignoring the article's own current slug)
        $newSlug = SlugHelper::makeUnique(
            $newSlugBase,
            fn (string $s): bool => $s !== $article->slug && $this->slugExists($s),
        );

        if ($newSlug !== $article->slug) {
            // Store old slug in history (if not already there)
            $alreadyInHistory = $this->db->fetchOne(
                'SELECT id FROM slug_history WHERE old_slug = ?',
                [$article->slug],
            );

            if ($alreadyInHistory === null) {
                $this->db->insert(
                    'INSERT INTO slug_history (article_id, old_slug, replaced_at) VALUES (?, ?, ?)',
                    [$id, $article->slug, $now],
                );
            }
        }

        $this->db->execute(
            'UPDATE articles SET title = ?, slug = ?, body = ?, updated_at = ? WHERE id = ?',
            [$title, $newSlug, $body, $now, $id],
        );

        return new Article($id, $title, $newSlug, $body, $article->createdAt, $now);
    }

    /** @return list<array<string, mixed>> */
    public function slugHistory(int $id): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM slug_history WHERE article_id = ? ORDER BY id DESC',
            [$id],
        );
    }

    private function slugExists(string $slug): bool
    {
        return $this->db->fetchOne('SELECT id FROM articles WHERE slug = ?', [$slug]) !== null
            || $this->db->fetchOne('SELECT id FROM slug_history WHERE old_slug = ?', [$slug]) !== null;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Article
    {
        return new Article(
            (int) $row['id'],
            (string) $row['title'],
            (string) $row['slug'],
            (string) $row['body'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
