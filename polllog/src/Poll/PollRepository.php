<?php

declare(strict_types=1);

namespace PollLog\Poll;

use Nene2\Database\DatabaseQueryExecutorInterface;

class PollRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    public function createPoll(string $question, bool $isPublic, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO polls (question, is_public, created_at) VALUES (?, ?, ?)',
            [$question, $isPublic ? 1 : 0, $now],
        );
    }

    public function addOption(int $pollId, string $label, int $sortOrder): void
    {
        $this->db->execute(
            'INSERT INTO poll_options (poll_id, label, sort_order) VALUES (?, ?, ?)',
            [$pollId, $label, $sortOrder],
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM polls WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function options(int $pollId): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, label, sort_order FROM poll_options WHERE poll_id = ? ORDER BY sort_order ASC, id ASC',
            [$pollId],
        );
    }

    /** Verify the option belongs to the poll — blocks cross-poll option injection. */
    public function optionBelongsToPoll(int $optionId, int $pollId): bool
    {
        return $this->db->fetchOne(
            'SELECT id FROM poll_options WHERE id = ? AND poll_id = ?',
            [$optionId, $pollId],
        ) !== null;
    }

    public function hasVoted(int $pollId, int $userId): bool
    {
        return $this->db->fetchOne(
            'SELECT id FROM votes WHERE poll_id = ? AND user_id = ?',
            [$pollId, $userId],
        ) !== null;
    }

    public function insertVote(int $pollId, int $optionId, int $userId, string $now): void
    {
        $this->db->execute(
            'INSERT INTO votes (poll_id, option_id, user_id, created_at) VALUES (?, ?, ?, ?)',
            [$pollId, $optionId, $userId, $now],
        );
    }

    /**
     * LEFT JOIN so zero-vote options still appear.
     *
     * @return list<array<string, mixed>>
     */
    public function results(int $pollId): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT o.id, o.label, o.sort_order, COUNT(v.id) AS votes
             FROM poll_options o
             LEFT JOIN votes v ON v.option_id = o.id AND v.poll_id = o.poll_id
             WHERE o.poll_id = ?
             GROUP BY o.id, o.label, o.sort_order
             ORDER BY o.sort_order ASC, o.id ASC',
            [$pollId],
        );
    }
}
