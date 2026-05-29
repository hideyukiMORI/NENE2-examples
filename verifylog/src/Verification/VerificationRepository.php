<?php

declare(strict_types=1);

namespace VerifyLog\Verification;

use Nene2\Database\DatabaseQueryExecutorInterface;

class VerificationRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, contact, code_hash, attempts_count, verified_at, expires_at, created_at FROM verifications WHERE id = ?',
            [$id],
        );
    }

    public function create(string $contact, string $codeHash, string $expiresAt, string $now): int
    {
        // code_hash / verified_at are server-managed — never from the body.
        return $this->db->insert(
            'INSERT INTO verifications (contact, code_hash, attempts_count, verified_at, expires_at, created_at)
             VALUES (?, ?, 0, NULL, ?, ?)',
            [$contact, $codeHash, $expiresAt, $now],
        );
    }

    public function incrementAttempts(int $id): void
    {
        $this->db->execute('UPDATE verifications SET attempts_count = attempts_count + 1 WHERE id = ?', [$id]);
    }

    public function markVerified(int $id, string $now): void
    {
        $this->db->execute('UPDATE verifications SET verified_at = ? WHERE id = ?', [$now, $id]);
    }
}
