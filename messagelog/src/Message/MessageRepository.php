<?php

declare(strict_types=1);

namespace Message\Message;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class MessageRepository
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
        return $this->executor->fetchOne('SELECT id FROM users WHERE id = ?', [$id]) !== null;
    }

    /**
     * Start a conversation between two users. Returns the conversation id.
     * If a conversation already exists (in either direction), returns existing id.
     */
    public function findOrCreateConversation(int $initiatorId, int $recipientId, string $now): int
    {
        $existing = $this->findConversation($initiatorId, $recipientId);

        if ($existing !== null) {
            return $existing;
        }

        $this->executor->execute(
            'INSERT INTO conversations (initiator_id, recipient_id, created_at) VALUES (?, ?, ?)',
            [$initiatorId, $recipientId, $now],
        );

        return (int) $this->executor->lastInsertId();
    }

    /**
     * Find conversation id between two users (direction-agnostic).
     */
    public function findConversation(int $userA, int $userB): ?int
    {
        $row = $this->executor->fetchOne(
            'SELECT id FROM conversations
             WHERE (initiator_id = ? AND recipient_id = ?)
                OR (initiator_id = ? AND recipient_id = ?)',
            [$userA, $userB, $userB, $userA],
        );

        if ($row === null) {
            return null;
        }

        $arr = (array) $row;

        return isset($arr['id']) ? (int) $arr['id'] : null;
    }

    /** @return array{id: int, initiator_id: int, recipient_id: int, created_at: string}|null */
    public function findConversationById(int $id): ?array
    {
        $row = $this->executor->fetchOne(
            'SELECT id, initiator_id, recipient_id, created_at FROM conversations WHERE id = ?',
            [$id],
        );

        if ($row === null) {
            return null;
        }

        return $this->hydrateConversation((array) $row);
    }

    /**
     * Check whether a user is a participant in a conversation.
     */
    public function isParticipant(int $conversationId, int $userId): bool
    {
        return $this->executor->fetchOne(
            'SELECT id FROM conversations
             WHERE id = ? AND (initiator_id = ? OR recipient_id = ?)',
            [$conversationId, $userId, $userId],
        ) !== null;
    }

    public function sendMessage(int $conversationId, int $senderId, string $content, string $now): int
    {
        $this->executor->execute(
            'INSERT INTO messages (conversation_id, sender_id, content, created_at) VALUES (?, ?, ?, ?)',
            [$conversationId, $senderId, $content, $now],
        );

        return (int) $this->executor->lastInsertId();
    }

    /** @return array<int, array{id: int, conversation_id: int, sender_id: int, content: string, created_at: string}> */
    public function listMessages(int $conversationId): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT id, conversation_id, sender_id, content, created_at FROM messages
             WHERE conversation_id = ?
             ORDER BY id ASC',
            [$conversationId],
        );

        return array_map(fn(mixed $row) => $this->hydrateMessage((array) $row), $rows);
    }

    /** @return array<int, array{id: int, initiator_id: int, recipient_id: int, created_at: string}> */
    public function listUserConversations(int $userId): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT id, initiator_id, recipient_id, created_at FROM conversations
             WHERE initiator_id = ? OR recipient_id = ?
             ORDER BY id DESC',
            [$userId, $userId],
        );

        return array_map(fn(mixed $row) => $this->hydrateConversation((array) $row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, initiator_id: int, recipient_id: int, created_at: string}
     */
    private function hydrateConversation(array $row): array
    {
        return [
            'id'           => isset($row['id']) ? (int) $row['id'] : 0,
            'initiator_id' => isset($row['initiator_id']) ? (int) $row['initiator_id'] : 0,
            'recipient_id' => isset($row['recipient_id']) ? (int) $row['recipient_id'] : 0,
            'created_at'   => isset($row['created_at']) && is_string($row['created_at']) ? $row['created_at'] : '',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, conversation_id: int, sender_id: int, content: string, created_at: string}
     */
    private function hydrateMessage(array $row): array
    {
        return [
            'id'              => isset($row['id']) ? (int) $row['id'] : 0,
            'conversation_id' => isset($row['conversation_id']) ? (int) $row['conversation_id'] : 0,
            'sender_id'       => isset($row['sender_id']) ? (int) $row['sender_id'] : 0,
            'content'         => isset($row['content']) && is_string($row['content']) ? $row['content'] : '',
            'created_at'      => isset($row['created_at']) && is_string($row['created_at']) ? $row['created_at'] : '',
        ];
    }
}
