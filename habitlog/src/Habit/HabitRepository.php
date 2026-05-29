<?php

declare(strict_types=1);

namespace HabitLog\Habit;

use Nene2\Database\DatabaseConstraintException;
use Nene2\Database\DatabaseQueryExecutorInterface;

class HabitRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null owner-scoped */
    public function findOwned(int $id, int $ownerId): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, owner_id, name, description, frequency, created_at FROM habits WHERE id = ? AND owner_id = ?',
            [$id, $ownerId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function listOwned(int $ownerId, ?string $frequency): array
    {
        $conds = ['owner_id = ?'];
        $params = [$ownerId];
        if ($frequency !== null) {
            $conds[] = 'frequency = ?';
            $params[] = $frequency;
        }
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, owner_id, name, description, frequency, created_at FROM habits WHERE '
            . implode(' AND ', $conds) . ' ORDER BY id ASC',
            $params,
        );
    }

    public function create(int $ownerId, string $name, string $description, string $frequency, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO habits (owner_id, name, description, frequency, created_at) VALUES (?, ?, ?, ?, ?)',
            [$ownerId, $name, $description, $frequency, $now],
        );
    }

    public function delete(int $id, int $ownerId): bool
    {
        return $this->db->execute('DELETE FROM habits WHERE id = ? AND owner_id = ?', [$id, $ownerId]) > 0;
    }

    /**
     * Record a completion. Duplicate (habit, date) → 'duplicate' via the
     * UNIQUE constraint.
     *
     * @return 'ok'|'duplicate'
     */
    public function complete(int $habitId, string $completedOn, string $note): string
    {
        try {
            $this->db->execute(
                'INSERT INTO completions (habit_id, completed_on, note) VALUES (?, ?, ?)',
                [$habitId, $completedOn, $note],
            );
        } catch (DatabaseConstraintException) {
            return 'duplicate';
        }
        return 'ok';
    }

    /** @return list<array<string, mixed>> */
    public function completions(int $habitId): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, completed_on, note FROM completions WHERE habit_id = ? ORDER BY completed_on DESC',
            [$habitId],
        );
    }

    /** Consecutive-day streak counting back from $today. */
    public function streak(int $habitId, string $today): int
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->fetchAll(
            'SELECT completed_on FROM completions WHERE habit_id = ? ORDER BY completed_on DESC',
            [$habitId],
        );

        $streak = 0;
        $expected = new \DateTimeImmutable($today);
        foreach ($rows as $row) {
            if ((string) $row['completed_on'] !== $expected->format('Y-m-d')) {
                break;
            }
            $streak++;
            $expected = $expected->modify('-1 day');
        }
        return $streak;
    }
}
