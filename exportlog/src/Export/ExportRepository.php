<?php

declare(strict_types=1);

namespace Export\Export;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class ExportRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {
    }

    public function createUser(string $email, string $name, string $phone, string $passwordHash, string $now): ?User
    {
        try {
            $this->executor->execute(
                'INSERT INTO users (email, name, phone, password_hash, created_at) VALUES (?, ?, ?, ?, ?)',
                [$email, $name, $phone, $passwordHash, $now],
            );
        } catch (\RuntimeException) {
            return null;
        }

        return $this->findUserByEmail($email);
    }

    public function findUserById(int $id): ?User
    {
        $row = $this->executor->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);

        return $row !== null ? $this->hydrateUser($row) : null;
    }

    public function findUserByEmail(string $email): ?User
    {
        $row = $this->executor->fetchOne('SELECT * FROM users WHERE email = ?', [$email]);

        return $row !== null ? $this->hydrateUser($row) : null;
    }

    public function createExport(int $userId, string $token, string $expiresAt, string $now): DataExport
    {
        $this->executor->execute(
            'INSERT INTO data_exports (user_id, token, status, payload, expires_at, created_at) VALUES (?, ?, ?, NULL, ?, ?)',
            [$userId, $token, 'pending', $expiresAt, $now],
        );

        return $this->findExportByToken($token);
    }

    public function findExportByToken(string $token): DataExport
    {
        $row = $this->executor->fetchOne('SELECT * FROM data_exports WHERE token = ?', [$token]);

        if ($row === null) {
            throw new \RuntimeException('Export not found');
        }

        return $this->hydrateExport($row);
    }

    public function findExportByTokenOrNull(string $token): ?DataExport
    {
        $row = $this->executor->fetchOne('SELECT * FROM data_exports WHERE token = ?', [$token]);

        return $row !== null ? $this->hydrateExport($row) : null;
    }

    /**
     * Build and store the export payload. Omits sensitive fields (password_hash, phone).
     *
     * @param array<int, array<string, mixed>> $activities
     */
    public function processExport(string $token, User $user, array $activities, string $now): DataExport
    {
        $payload = json_encode([
            'exported_at' => $now,
            'user'        => [
                'id'         => $user->id,
                'email'      => $user->email,
                'name'       => $user->name,
                'created_at' => $user->createdAt,
                // password_hash and phone intentionally excluded
            ],
            'activities' => $activities,
        ], JSON_THROW_ON_ERROR);

        $this->executor->execute(
            "UPDATE data_exports SET status = 'ready', payload = ? WHERE token = ?",
            [$payload, $token],
        );

        return $this->findExportByToken($token);
    }

    /** @param array<string, mixed> $row */
    private function hydrateUser(array $row): User
    {
        return new User(
            id:           (int) $row['id'],
            email:        (string) $row['email'],
            name:         (string) $row['name'],
            phone:        (string) $row['phone'],
            passwordHash: (string) $row['password_hash'],
            createdAt:    (string) $row['created_at'],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateExport(array $row): DataExport
    {
        return new DataExport(
            id:        (int) $row['id'],
            userId:    (int) $row['user_id'],
            token:     (string) $row['token'],
            status:    (string) $row['status'],
            payload:   isset($row['payload']) ? (string) $row['payload'] : null,
            expiresAt: (string) $row['expires_at'],
            createdAt: (string) $row['created_at'],
        );
    }
}
