<?php

declare(strict_types=1);

namespace Relations\Article;

use Nene2\Database\DatabaseTransactionManagerInterface;

/**
 * Adds/removes a relation together with its inverse in one transaction so the
 * graph is always consistent in both directions.
 */
final class RelationService
{
    /** type → inverse type. */
    public const array INVERSE = [
        'related' => 'related',
        'sequel' => 'prequel',
        'prequel' => 'sequel',
        'reference' => 'reference',
    ];

    public function __construct(
        private readonly DatabaseTransactionManagerInterface $tx,
    ) {
    }

    public function add(int $articleId, int $relatedId, string $type, string $now): void
    {
        $inverse = self::INVERSE[$type];
        $this->tx->transactional(function ($executor) use ($articleId, $relatedId, $type, $inverse, $now): void {
            $executor->execute(
                'INSERT INTO article_relations (article_id, related_id, relation_type, created_at) VALUES (?, ?, ?, ?)',
                [$articleId, $relatedId, $type, $now],
            );
            $executor->execute(
                'INSERT INTO article_relations (article_id, related_id, relation_type, created_at) VALUES (?, ?, ?, ?)',
                [$relatedId, $articleId, $inverse, $now],
            );
        });
    }

    public function remove(int $articleId, int $relatedId, string $type): void
    {
        $inverse = self::INVERSE[$type];
        $this->tx->transactional(function ($executor) use ($articleId, $relatedId, $type, $inverse): void {
            $executor->execute(
                'DELETE FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
                [$articleId, $relatedId, $type],
            );
            $executor->execute(
                'DELETE FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
                [$relatedId, $articleId, $inverse],
            );
        });
    }
}
