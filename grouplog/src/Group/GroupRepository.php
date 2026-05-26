<?php

declare(strict_types=1);

namespace Group\Group;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class GroupRepository
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

    public function createGroup(string $name, int $ownerId, string $now): int
    {
        $this->executor->execute(
            'INSERT INTO user_groups (name, owner_id, created_at) VALUES (?, ?, ?)',
            [$name, $ownerId, $now],
        );

        $groupId = (int) $this->executor->lastInsertId();

        // Owner is automatically a member with 'owner' role
        $this->executor->execute(
            'INSERT INTO memberships (group_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)',
            [$groupId, $ownerId, 'owner', $now],
        );

        return $groupId;
    }

    /** @return array{id: int, name: string, owner_id: int, created_at: string}|null */
    public function findGroupById(int $id): ?array
    {
        $row = $this->executor->fetchOne(
            'SELECT id, name, owner_id, created_at FROM user_groups WHERE id = ?',
            [$id],
        );

        return $row !== null ? $this->hydrateGroup((array) $row) : null;
    }

    /** @return array{id: int, group_id: int, user_id: int, role: string, joined_at: string}|null */
    public function findMembership(int $groupId, int $userId): ?array
    {
        $row = $this->executor->fetchOne(
            'SELECT id, group_id, user_id, role, joined_at FROM memberships WHERE group_id = ? AND user_id = ?',
            [$groupId, $userId],
        );

        return $row !== null ? $this->hydrateMembership((array) $row) : null;
    }

    /** @return array<int, array{id: int, user_id: int, name: string, role: string, joined_at: string}> */
    public function listMembers(int $groupId): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT m.id, m.user_id, u.name, m.role, m.joined_at
             FROM memberships m
             INNER JOIN users u ON u.id = m.user_id
             WHERE m.group_id = ?
             ORDER BY m.id ASC',
            [$groupId],
        );

        return array_map(fn(mixed $row) => $this->hydrateMemberEntry((array) $row), $rows);
    }

    public function addMember(int $groupId, int $userId, MemberRole $role, string $now): void
    {
        $this->executor->execute(
            'INSERT INTO memberships (group_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)',
            [$groupId, $userId, $role->value, $now],
        );
    }

    public function removeMember(int $groupId, int $userId): bool
    {
        $count = $this->executor->execute(
            'DELETE FROM memberships WHERE group_id = ? AND user_id = ?',
            [$groupId, $userId],
        );

        return $count > 0;
    }

    public function changeRole(int $groupId, int $userId, MemberRole $role): bool
    {
        $count = $this->executor->execute(
            'UPDATE memberships SET role = ? WHERE group_id = ? AND user_id = ?',
            [$role->value, $groupId, $userId],
        );

        return $count > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, name: string, owner_id: int, created_at: string}
     */
    private function hydrateGroup(array $row): array
    {
        return [
            'id'         => isset($row['id']) ? (int) $row['id'] : 0,
            'name'       => isset($row['name']) && is_string($row['name']) ? $row['name'] : '',
            'owner_id'   => isset($row['owner_id']) ? (int) $row['owner_id'] : 0,
            'created_at' => isset($row['created_at']) && is_string($row['created_at']) ? $row['created_at'] : '',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, group_id: int, user_id: int, role: string, joined_at: string}
     */
    private function hydrateMembership(array $row): array
    {
        return [
            'id'        => isset($row['id']) ? (int) $row['id'] : 0,
            'group_id'  => isset($row['group_id']) ? (int) $row['group_id'] : 0,
            'user_id'   => isset($row['user_id']) ? (int) $row['user_id'] : 0,
            'role'      => isset($row['role']) && is_string($row['role']) ? $row['role'] : 'member',
            'joined_at' => isset($row['joined_at']) && is_string($row['joined_at']) ? $row['joined_at'] : '',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, user_id: int, name: string, role: string, joined_at: string}
     */
    private function hydrateMemberEntry(array $row): array
    {
        return [
            'id'        => isset($row['id']) ? (int) $row['id'] : 0,
            'user_id'   => isset($row['user_id']) ? (int) $row['user_id'] : 0,
            'name'      => isset($row['name']) && is_string($row['name']) ? $row['name'] : '',
            'role'      => isset($row['role']) && is_string($row['role']) ? $row['role'] : 'member',
            'joined_at' => isset($row['joined_at']) && is_string($row['joined_at']) ? $row['joined_at'] : '',
        ];
    }
}
