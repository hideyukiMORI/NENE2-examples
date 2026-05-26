<?php

declare(strict_types=1);

namespace PrefLog\Pref;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class PrefRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $executor)
    {
    }

    public function findUserById(int $userId): ?array
    {
        return $this->executor->fetchOne('SELECT id, name FROM users WHERE id = ?', [$userId]) ?: null;
    }

    public function findPreference(int $userId, string $key): ?array
    {
        return $this->executor->fetchOne(
            'SELECT pref_key, pref_value, updated_at FROM user_preferences WHERE user_id = ? AND pref_key = ?',
            [$userId, $key]
        ) ?: null;
    }

    public function findAllPreferences(int $userId): array
    {
        return $this->executor->fetchAll(
            'SELECT pref_key, pref_value, updated_at FROM user_preferences WHERE user_id = ? ORDER BY pref_key ASC',
            [$userId]
        );
    }

    public function upsertPreference(int $userId, string $key, string $value, string $now): void
    {
        $existing = $this->findPreference($userId, $key);
        if ($existing !== null) {
            $this->executor->execute(
                'UPDATE user_preferences SET pref_value = ?, updated_at = ? WHERE user_id = ? AND pref_key = ?',
                [$value, $now, $userId, $key]
            );
        } else {
            $this->executor->execute(
                'INSERT INTO user_preferences (user_id, pref_key, pref_value, updated_at) VALUES (?, ?, ?, ?)',
                [$userId, $key, $value, $now]
            );
        }
    }

    public function deletePreference(int $userId, string $key): bool
    {
        $existing = $this->findPreference($userId, $key);
        if ($existing === null) {
            return false;
        }
        $this->executor->execute(
            'DELETE FROM user_preferences WHERE user_id = ? AND pref_key = ?',
            [$userId, $key]
        );
        return true;
    }
}
