<?php

declare(strict_types=1);

namespace Pubschedulelog\Article;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class ArticleRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $db,
    ) {
    }

    public function create(int $authorId, string $title, string $body, string $now): Article
    {
        $id = $this->db->insert(
            'INSERT INTO articles (author_id, title, body, status, publish_at, published_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, NULL, NULL, ?, ?)',
            [$authorId, $title, $body, ArticleStatus::Draft->value, $now, $now],
        );

        return new Article($id, $authorId, $title, $body, ArticleStatus::Draft, null, null, $now, $now);
    }

    public function findById(int $id): ?Article
    {
        $row = $this->db->fetchOne('SELECT * FROM articles WHERE id = ?', [$id]);

        return $row !== null ? $this->hydrate($row) : null;
    }

    /**
     * List articles. Filters:
     *  - status: if given, only that status (e.g. 'published')
     *  - authorId: if given, only that author's articles
     *
     * @return list<Article>
     */
    public function list(?ArticleStatus $status, ?int $authorId): array
    {
        $where  = [];
        $params = [];

        if ($status !== null) {
            $where[]  = 'status = ?';
            $params[] = $status->value;
        }

        if ($authorId !== null) {
            $where[]  = 'author_id = ?';
            $params[] = $authorId;
        }

        $sql  = 'SELECT * FROM articles';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY id DESC';

        $rows = $this->db->fetchAll($sql, $params);

        return array_map($this->hydrate(...), $rows);
    }

    public function update(int $id, string $title, string $body, string $now): bool
    {
        return $this->db->execute(
            'UPDATE articles SET title = ?, body = ?, updated_at = ? WHERE id = ? AND status IN (?, ?)',
            [$title, $body, $now, $id, ArticleStatus::Draft->value, ArticleStatus::Scheduled->value],
        ) > 0;
    }

    /**
     * Schedule: set publish_at, move status to 'scheduled'.
     *
     * Validates:
     * - Article must exist and belong to actor
     * - publish_at must be in the future
     * - Status must allow transition to 'scheduled'
     */
    public function schedule(int $id, int $actorId, string $publishAt, string $now): Article
    {
        $article = $this->findById($id);
        if ($article === null) {
            throw new ArticleNotFoundException($id);
        }

        // Ownership check
        if ($article->authorId !== $actorId) {
            throw new ArticleNotFoundException($id); // 404 to prevent enumeration
        }

        // publish_at must be valid ISO 8601 and in the future
        $ts = strtotime($publishAt);
        if ($ts === false || $ts === -1) {
            throw new ArticleScheduleException('publish_at is not a valid datetime.');
        }

        if ($ts <= strtotime($now)) {
            throw new ArticleScheduleException('publish_at must be in the future.');
        }

        // Status transition guard
        if (!$article->status->canTransitionTo(ArticleStatus::Scheduled)) {
            throw new ArticleTransitionException($article->status, ArticleStatus::Scheduled);
        }

        $this->db->execute(
            'UPDATE articles SET status = ?, publish_at = ?, updated_at = ? WHERE id = ?',
            [ArticleStatus::Scheduled->value, $publishAt, $now, $id],
        );

        return $this->findById($id) ?? throw new ArticleNotFoundException($id);
    }

    /**
     * Publish immediately: move to 'published', set published_at = now.
     * Ownership check: actor must be the author.
     */
    public function publish(int $id, int $actorId, string $now): Article
    {
        $article = $this->findById($id);
        if ($article === null) {
            throw new ArticleNotFoundException($id);
        }

        if ($article->authorId !== $actorId) {
            throw new ArticleNotFoundException($id); // 404 to prevent enumeration
        }

        if (!$article->status->canTransitionTo(ArticleStatus::Published)) {
            throw new ArticleTransitionException($article->status, ArticleStatus::Published);
        }

        $this->db->execute(
            'UPDATE articles SET status = ?, published_at = ?, publish_at = NULL, updated_at = ? WHERE id = ?',
            [ArticleStatus::Published->value, $now, $now, $id],
        );

        return $this->findById($id) ?? throw new ArticleNotFoundException($id);
    }

    /**
     * Archive: move to 'archived'. Ownership check.
     */
    public function archive(int $id, int $actorId, string $now): Article
    {
        $article = $this->findById($id);
        if ($article === null) {
            throw new ArticleNotFoundException($id);
        }

        if ($article->authorId !== $actorId) {
            throw new ArticleNotFoundException($id);
        }

        if (!$article->status->canTransitionTo(ArticleStatus::Archived)) {
            throw new ArticleTransitionException($article->status, ArticleStatus::Archived);
        }

        $this->db->execute(
            'UPDATE articles SET status = ?, updated_at = ? WHERE id = ?',
            [ArticleStatus::Archived->value, $now, $id],
        );

        return $this->findById($id) ?? throw new ArticleNotFoundException($id);
    }

    /**
     * Unschedule: revert scheduled → draft (cancel a scheduled publish).
     */
    public function unschedule(int $id, int $actorId, string $now): Article
    {
        $article = $this->findById($id);
        if ($article === null) {
            throw new ArticleNotFoundException($id);
        }

        if ($article->authorId !== $actorId) {
            throw new ArticleNotFoundException($id);
        }

        if (!$article->status->canTransitionTo(ArticleStatus::Draft)) {
            throw new ArticleTransitionException($article->status, ArticleStatus::Draft);
        }

        $this->db->execute(
            'UPDATE articles SET status = ?, publish_at = NULL, updated_at = ? WHERE id = ?',
            [ArticleStatus::Draft->value, $now, $id],
        );

        return $this->findById($id) ?? throw new ArticleNotFoundException($id);
    }

    /**
     * Publish all due scheduled articles (those with publish_at <= now).
     *
     * This is a machine/admin operation — no ownership check.
     *
     * @return list<int> IDs of articles that were published
     */
    public function publishDue(string $now): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id FROM articles WHERE status = ? AND publish_at <= ? ORDER BY publish_at",
            [ArticleStatus::Scheduled->value, $now],
        );

        $published = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $this->db->execute(
                'UPDATE articles SET status = ?, published_at = ?, publish_at = NULL, updated_at = ? WHERE id = ?',
                [ArticleStatus::Published->value, $now, $now, $id],
            );
            $published[] = $id;
        }

        return $published;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Article
    {
        return new Article(
            (int) $row['id'],
            (int) $row['author_id'],
            (string) $row['title'],
            (string) $row['body'],
            ArticleStatus::from((string) $row['status']),
            $row['publish_at'] !== null ? (string) $row['publish_at'] : null,
            $row['published_at'] !== null ? (string) $row['published_at'] : null,
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
