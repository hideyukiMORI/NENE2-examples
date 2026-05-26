<?php

declare(strict_types=1);

namespace Notification\Notification;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class NotificationRepository
{
    public function __construct(
        private readonly DatabaseQueryExecutorInterface $executor,
    ) {}

    public function createUser(string $name, string $now): int
    {
        $this->executor->execute(
            'INSERT INTO users (name, created_at) VALUES (?, ?)',
            [$name, $now],
        );

        return (int) $this->executor->lastInsertId();
    }

    public function findUserById(int $id): bool
    {
        $row = $this->executor->fetchOne('SELECT id FROM users WHERE id = ?', [$id]);

        return $row !== null;
    }

    public function create(int $userId, string $title, string $body, string $now): Notification
    {
        $this->executor->execute(
            'INSERT INTO notifications (user_id, title, body, read_at, created_at) VALUES (?, ?, ?, NULL, ?)',
            [$userId, $title, $body, $now],
        );

        $id = (int) $this->executor->lastInsertId();

        return new Notification($id, $userId, $title, $body, null, $now);
    }

    /** @return Notification[] */
    public function findByUserId(int $userId, ?bool $unreadOnly = null): array
    {
        if ($unreadOnly === true) {
            $rows = $this->executor->fetchAll(
                'SELECT * FROM notifications WHERE user_id = ? AND read_at IS NULL ORDER BY id DESC',
                [$userId],
            );
        } else {
            $rows = $this->executor->fetchAll(
                'SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC',
                [$userId],
            );
        }

        return array_map(fn(mixed $row) => $this->hydrate((array) $row), $rows);
    }

    public function findById(int $id): ?Notification
    {
        $row = $this->executor->fetchOne('SELECT * FROM notifications WHERE id = ?', [$id]);

        if ($row === null) {
            return null;
        }

        return $this->hydrate((array) $row);
    }

    public function markAsRead(int $id, string $now): ?Notification
    {
        $existing = $this->findById($id);

        if ($existing === null) {
            return null;
        }

        if ($existing->isRead()) {
            return $existing;
        }

        $this->executor->execute(
            'UPDATE notifications SET read_at = ? WHERE id = ?',
            [$now, $id],
        );

        return $this->findById($id);
    }

    public function markAllAsRead(int $userId, string $now): int
    {
        $this->executor->execute(
            'UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL',
            [$now, $userId],
        );

        return $this->countUnread($userId);
    }

    public function countUnread(int $userId): int
    {
        $row = $this->executor->fetchOne(
            'SELECT COUNT(*) as cnt FROM notifications WHERE user_id = ? AND read_at IS NULL',
            [$userId],
        );

        if ($row === null) {
            return 0;
        }

        $arr = (array) $row;

        return isset($arr['cnt']) ? (int) $arr['cnt'] : 0;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Notification
    {
        return new Notification(
            id: isset($row['id']) ? (int) $row['id'] : 0,
            userId: isset($row['user_id']) ? (int) $row['user_id'] : 0,
            title: isset($row['title']) && is_string($row['title']) ? $row['title'] : '',
            body: isset($row['body']) && is_string($row['body']) ? $row['body'] : '',
            readAt: isset($row['read_at']) && is_string($row['read_at']) ? $row['read_at'] : null,
            createdAt: isset($row['created_at']) && is_string($row['created_at']) ? $row['created_at'] : '',
        );
    }
}
