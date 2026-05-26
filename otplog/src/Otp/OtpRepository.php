<?php

declare(strict_types=1);

namespace OtpLog\Otp;

use Nene2\Database\DatabaseQueryExecutorInterface;

class OtpRepository
{
    private const int MAX_ATTEMPTS = 3;
    private const int OTP_TTL_MINUTES = 5;
    private const int LOCK_MINUTES = 10;
    private const int SESSION_TTL_HOURS = 24;

    public function __construct(
        private readonly DatabaseQueryExecutorInterface $executor,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function findUserByEmail(string $email): ?array
    {
        return $this->executor->fetchOne('SELECT * FROM users WHERE email = ?', [$email]);
    }

    /** @return array<string, mixed>|null */
    public function findUserById(int $id): ?array
    {
        return $this->executor->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function findOrCreateUser(string $email, string $now): int
    {
        $user = $this->findUserByEmail($email);
        if ($user !== null) {
            return (int) $user['id'];
        }
        return $this->executor->insert('INSERT INTO users (email, created_at) VALUES (?, ?)', [$email, $now]);
    }

    /** @return array<string, mixed>|null */
    public function findLatestOtpForUser(int $userId): ?array
    {
        return $this->executor->fetchOne(
            'SELECT * FROM otp_codes WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            [$userId]
        );
    }

    public function createOtp(int $userId, string $codeHash, string $now): int
    {
        $expiresAt = date('c', strtotime($now) + self::OTP_TTL_MINUTES * 60);
        return $this->executor->insert(
            'INSERT INTO otp_codes (user_id, code_hash, expires_at, used_at, attempt_count, locked_until, created_at) VALUES (?, ?, ?, NULL, 0, NULL, ?)',
            [$userId, $codeHash, $expiresAt, $now]
        );
    }

    public function incrementAttempt(int $otpId, string $now): void
    {
        $otp = $this->executor->fetchOne('SELECT * FROM otp_codes WHERE id = ?', [$otpId]);
        if ($otp === null) {
            return;
        }
        $newCount = (int) $otp['attempt_count'] + 1;
        $lockedUntil = null;
        if ($newCount >= self::MAX_ATTEMPTS) {
            $lockedUntil = date('c', strtotime($now) + self::LOCK_MINUTES * 60);
        }
        $this->executor->execute(
            'UPDATE otp_codes SET attempt_count = ?, locked_until = ? WHERE id = ?',
            [$newCount, $lockedUntil, $otpId]
        );
    }

    public function markOtpUsed(int $otpId, string $now): void
    {
        $this->executor->execute('UPDATE otp_codes SET used_at = ? WHERE id = ?', [$now, $otpId]);
    }

    public function createSession(int $userId, string $tokenHash, string $now): int
    {
        $expiresAt = date('c', strtotime($now) + self::SESSION_TTL_HOURS * 3600);
        return $this->executor->insert(
            'INSERT INTO otp_sessions (user_id, session_token_hash, expires_at, revoked_at, created_at) VALUES (?, ?, ?, NULL, ?)',
            [$userId, $tokenHash, $expiresAt, $now]
        );
    }

    /** @return array<string, mixed>|null */
    public function findSessionByTokenHash(string $tokenHash): ?array
    {
        return $this->executor->fetchOne(
            'SELECT * FROM otp_sessions WHERE session_token_hash = ?',
            [$tokenHash]
        );
    }

    public function revokeSession(string $tokenHash, string $now): void
    {
        $this->executor->execute(
            'UPDATE otp_sessions SET revoked_at = ? WHERE session_token_hash = ?',
            [$now, $tokenHash]
        );
    }

    public static function maxAttempts(): int
    {
        return self::MAX_ATTEMPTS;
    }

    public static function otpTtlMinutes(): int
    {
        return self::OTP_TTL_MINUTES;
    }

    public static function lockMinutes(): int
    {
        return self::LOCK_MINUTES;
    }
}
