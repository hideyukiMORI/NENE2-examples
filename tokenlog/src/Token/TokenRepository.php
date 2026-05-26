<?php

declare(strict_types=1);

namespace Token\Token;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class TokenRepository
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
     * Issue a new token. Returns the raw (plaintext) token — stored only as hash.
     */
    public function issueToken(int $userId, TokenScope $scope, string $label, string $now): string
    {
        $raw  = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);

        $this->executor->execute(
            'INSERT INTO tokens (user_id, token_hash, scope, label, created_at) VALUES (?, ?, ?, ?, ?)',
            [$userId, $hash, $scope->value, $label, $now],
        );

        return $raw;
    }

    /** @return array<int, array{id: int, scope: string, label: string, created_at: string, revoked: bool}> */
    public function listTokens(int $userId): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT id, scope, label, created_at, revoked_at FROM tokens WHERE user_id = ? ORDER BY id DESC',
            [$userId],
        );

        return array_map(fn(mixed $row) => $this->hydrateToken((array) $row), $rows);
    }

    /** @return array{id: int, user_id: int, revoked: bool}|null */
    public function findTokenById(int $id): ?array
    {
        $row = $this->executor->fetchOne(
            'SELECT id, user_id, revoked_at FROM tokens WHERE id = ?',
            [$id],
        );

        if ($row === null) {
            return null;
        }

        $arr = (array) $row;

        return [
            'id'      => isset($arr['id']) ? (int) $arr['id'] : 0,
            'user_id' => isset($arr['user_id']) ? (int) $arr['user_id'] : 0,
            'revoked' => isset($arr['revoked_at']),
        ];
    }

    public function revokeToken(int $tokenId, string $now): bool
    {
        $count = $this->executor->execute(
            'UPDATE tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
            [$now, $tokenId],
        );

        return $count > 0;
    }

    /** @return array{valid: bool, user_id: int, scope: string}|null */
    public function verifyToken(string $rawToken): ?array
    {
        $hash = hash('sha256', $rawToken);
        $row  = $this->executor->fetchOne(
            'SELECT id, user_id, scope, revoked_at FROM tokens WHERE token_hash = ?',
            [$hash],
        );

        if ($row === null) {
            return null;
        }

        $arr = (array) $row;

        return [
            'valid'   => !isset($arr['revoked_at']),
            'user_id' => isset($arr['user_id']) ? (int) $arr['user_id'] : 0,
            'scope'   => isset($arr['scope']) && is_string($arr['scope']) ? $arr['scope'] : 'read',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, scope: string, label: string, created_at: string, revoked: bool}
     */
    private function hydrateToken(array $row): array
    {
        return [
            'id'         => isset($row['id']) ? (int) $row['id'] : 0,
            'scope'      => isset($row['scope']) && is_string($row['scope']) ? $row['scope'] : 'read',
            'label'      => isset($row['label']) && is_string($row['label']) ? $row['label'] : '',
            'created_at' => isset($row['created_at']) && is_string($row['created_at']) ? $row['created_at'] : '',
            'revoked'    => isset($row['revoked_at']),
        ];
    }
}
