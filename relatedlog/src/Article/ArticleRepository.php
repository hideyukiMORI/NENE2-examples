<?php

declare(strict_types=1);

namespace Relatedlog\Article;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class ArticleRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $db,
    ) {
    }

    public function create(string $title, string $body, string $now): Article
    {
        $id = $this->db->insert(
            'INSERT INTO articles (title, body, created_at) VALUES (?, ?, ?)',
            [$title, $body, $now],
        );

        return new Article($id, $title, $body, $now);
    }

    public function findById(int $id): ?Article
    {
        $row = $this->db->fetchOne('SELECT * FROM articles WHERE id = ?', [$id]);

        return $row !== null ? $this->hydrateArticle($row) : null;
    }

    /**
     * Returns article with all its outgoing relations and the related article stubs.
     *
     * @return array{article: Article, relations: list<array{relation: ArticleRelation, related: Article}>}
     */
    public function findWithRelations(int $id): ?array
    {
        $article = $this->findById($id);
        if ($article === null) {
            return null;
        }

        $rows = $this->db->fetchAll(
            'SELECT r.*, a.title AS rel_title, a.body AS rel_body, a.created_at AS rel_created_at
             FROM article_relations r
             JOIN articles a ON a.id = r.related_id
             WHERE r.article_id = ?
             ORDER BY r.id',
            [$id],
        );

        $relations = array_map(function (array $row) use ($id): array {
            $relation = new ArticleRelation(
                (int) $row['id'],
                $id,
                (int) $row['related_id'],
                RelationType::from((string) $row['relation_type']),
                (string) $row['created_at'],
            );
            $related  = new Article(
                (int) $row['related_id'],
                (string) $row['rel_title'],
                (string) $row['rel_body'],
                (string) $row['rel_created_at'],
            );

            return ['relation' => $relation, 'related' => $related];
        }, $rows);

        return ['article' => $article, 'relations' => $relations];
    }

    /**
     * Adds a directed relation from article_id to related_id of the given type.
     *
     * For symmetric types (related, reference) also inserts the inverse row.
     * For asymmetric types (sequel ↔ prequel) inserts the inverse row.
     *
     * Both directions are stored so each article can query its own outgoing relations.
     *
     * Throws RelationAlreadyExistsException if the relation already exists.
     * Throws ArticleNotFoundException if either article does not exist.
     */
    public function addRelation(int $articleId, int $relatedId, RelationType $type, string $now): ArticleRelation
    {
        // Validate both articles exist
        if ($this->findById($articleId) === null) {
            throw new ArticleNotFoundException($articleId);
        }

        if ($this->findById($relatedId) === null) {
            throw new ArticleNotFoundException($relatedId);
        }

        // Check for duplicate
        $existing = $this->db->fetchOne(
            'SELECT id FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
            [$articleId, $relatedId, $type->value],
        );

        if ($existing !== null) {
            throw new RelationAlreadyExistsException($articleId, $relatedId, $type);
        }

        // Insert the forward relation
        $id = $this->db->insert(
            'INSERT INTO article_relations (article_id, related_id, relation_type, created_at) VALUES (?, ?, ?, ?)',
            [$articleId, $relatedId, $type->value, $now],
        );

        // Insert the inverse relation (if not already there)
        $inverse         = $type->inverse();
        $inverseExisting = $this->db->fetchOne(
            'SELECT id FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
            [$relatedId, $articleId, $inverse->value],
        );

        if ($inverseExisting === null) {
            $this->db->insert(
                'INSERT INTO article_relations (article_id, related_id, relation_type, created_at) VALUES (?, ?, ?, ?)',
                [$relatedId, $articleId, $inverse->value, $now],
            );
        }

        return new ArticleRelation($id, $articleId, $relatedId, $type, $now);
    }

    /**
     * Removes a relation (both forward and inverse).
     */
    public function removeRelation(int $articleId, int $relatedId, RelationType $type): bool
    {
        $deleted = $this->db->execute(
            'DELETE FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
            [$articleId, $relatedId, $type->value],
        );

        // Remove inverse
        $inverse = $type->inverse();
        $this->db->execute(
            'DELETE FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
            [$relatedId, $articleId, $inverse->value],
        );

        return $deleted > 0;
    }

    /**
     * Lists all outgoing relations of an article filtered by type.
     *
     * @return list<ArticleRelation>
     */
    public function listRelations(int $articleId, ?RelationType $type): array
    {
        if ($type !== null) {
            $rows = $this->db->fetchAll(
                'SELECT * FROM article_relations WHERE article_id = ? AND relation_type = ? ORDER BY id',
                [$articleId, $type->value],
            );
        } else {
            $rows = $this->db->fetchAll(
                'SELECT * FROM article_relations WHERE article_id = ? ORDER BY id',
                [$articleId],
            );
        }

        return array_map($this->hydrateRelation(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrateArticle(array $row): Article
    {
        return new Article(
            (int) $row['id'],
            (string) $row['title'],
            (string) $row['body'],
            (string) $row['created_at'],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateRelation(array $row): ArticleRelation
    {
        return new ArticleRelation(
            (int) $row['id'],
            (int) $row['article_id'],
            (int) $row['related_id'],
            RelationType::from((string) $row['relation_type']),
            (string) $row['created_at'],
        );
    }
}
