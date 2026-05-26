<?php

declare(strict_types=1);

namespace Profile\Profile;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class ProfileRepository
{
    public function __construct(
        private readonly DatabaseQueryExecutorInterface $executor,
    ) {}

    public function createUser(string $email, string $now): int
    {
        $this->executor->execute(
            'INSERT INTO users (email, created_at) VALUES (?, ?)',
            [$email, $now],
        );

        return (int) $this->executor->lastInsertId();
    }

    public function findUserById(int $id): bool
    {
        return $this->executor->fetchOne('SELECT id FROM users WHERE id = ?', [$id]) !== null;
    }

    public function createProfile(int $userId, string $displayName, string $bio, string $avatarUrl, string $now): UserProfile
    {
        $this->executor->execute(
            'INSERT INTO profiles (user_id, display_name, bio, avatar_url, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$userId, $displayName, $bio, $avatarUrl, $now],
        );

        $id = (int) $this->executor->lastInsertId();

        return new UserProfile($id, $userId, $displayName, $bio, $avatarUrl, $now);
    }

    public function findByUserId(int $userId): ?UserProfile
    {
        $row = $this->executor->fetchOne('SELECT * FROM profiles WHERE user_id = ?', [$userId]);

        if ($row === null) {
            return null;
        }

        return $this->hydrate((array) $row);
    }

    public function updateProfile(int $userId, string $displayName, string $bio, string $avatarUrl, string $now): ?UserProfile
    {
        $existing = $this->findByUserId($userId);

        if ($existing === null) {
            return null;
        }

        $this->executor->execute(
            'UPDATE profiles SET display_name = ?, bio = ?, avatar_url = ?, updated_at = ? WHERE user_id = ?',
            [$displayName, $bio, $avatarUrl, $now, $userId],
        );

        return new UserProfile($existing->id, $userId, $displayName, $bio, $avatarUrl, $now);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): UserProfile
    {
        return new UserProfile(
            id: isset($row['id']) ? (int) $row['id'] : 0,
            userId: isset($row['user_id']) ? (int) $row['user_id'] : 0,
            displayName: isset($row['display_name']) && is_string($row['display_name']) ? $row['display_name'] : '',
            bio: isset($row['bio']) && is_string($row['bio']) ? $row['bio'] : '',
            avatarUrl: isset($row['avatar_url']) && is_string($row['avatar_url']) ? $row['avatar_url'] : '',
            updatedAt: isset($row['updated_at']) && is_string($row['updated_at']) ? $row['updated_at'] : '',
        );
    }
}
